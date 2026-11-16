<?php

namespace SilverStripe\CMS\Controllers;

use SilverStripe\Admin\AdminController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Convert;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\PermissionCheckable;

/**
 * This controller serves JSON endpoints at admin/pages/tree that read the page tree of CMSMain.
 *
 * The React tree view in admin consumes the responses. The routes and the shape of the responses are internal to the
 * CMS and may change in a minor release. A client takes the URLs from Link().
 *
 * The actions respond to an error with a JSON error from AdminController::jsonError():
 * - 400 for an ID that is not a whole number.
 * - 403 for the path of a record that the member cannot view.
 * - 404 for a record that does not exist, or for a request without an action.
 *
 * A failed access check responds as AdminController::init() does. A request that is not GET gets an error without a
 * JSON body.
 */
class CMSMainTreeController extends AdminController
{
    private static string $url_segment = 'pages/tree';

    /**
     * A priority above CMSMain, CMSPageEditController and CMSPageSettingsController, which share the pages prefix.
     */
    private static int $url_priority = 43;

    /**
     * The permission code of CMSMain.
     *
     * canView() checks access through CMSMain, so the access check does not read this value. Keep this value the same
     * as CMSMain::$required_permission_codes for callers of getRequiredPermissions().
     */
    private static string|array $required_permission_codes = 'CMS_ACCESS_CMSMain';

    private static array $url_handlers = [
        'GET children/$ParentID' => 'apiReadChildren',
        'GET path/$ID' => 'apiReadPath',
    ];

    private static array $allowed_actions = [
        'apiReadChildren',
        'apiReadPath',
    ];

    private ?CMSMain $cmsMain = null;

    /**
     * The number of children of each record that the tree lists, for a record with hide_from_cms_tree classes.
     */
    private array $treeChildCounts = [];

    /**
     * Respond to a request without an action with a 404 JSON error.
     */
    public function index(HTTPRequest $request): void
    {
        $this->jsonError(404);
    }

    /**
     * Respond to `children/{parentID}` with one level of the tree: the nodes of the children of a record.
     *
     * A parent ID of 0 gives the top level. For a parent with too many children to list, this method responds with no
     * nodes and `limited` set to true. Only the model scopes the children, through hide_from_cms_tree and the Hierarchy
     * hooks that return the children.
     */
    public function apiReadChildren(HTTPRequest $request): HTTPResponse
    {
        $this->requireGetRequest($request);
        $parentID = $this->getTreeRequestID($request, 'ParentID');
        // The top level gets a new record because the parent goes on to getTreeNodeData() and the updateTreeNodeData
        // hook. A change there to DataObject::singleton() would reach every other caller of it.
        $parent = $parentID ? $this->getTreeRecord($parentID) : $this->getModelClass()::create();
        if ($parentID && !$this->canListTreeNode($parent)) {
            $this->jsonError(403);
        }
        if ($this->isTreeNodeLimited($parent)) {
            return $this->jsonSuccess(200, ['nodes' => [], 'limited' => true]);
        }

        /** @var DataObject&Hierarchy $parent */
        $childrenMethod = $parent->getHierarchyBaseClass()::config()->get('tree_children_method');
        $children = $parent->$childrenMethod();
        // Hierarchy::showingCMSTree() recognises only the tree actions of LeftAndMain, so the children method keeps the
        // hide_from_cms_tree classes and this method excludes them.
        $hiddenClasses = $parent->config()->get('hide_from_cms_tree');
        if ($hiddenClasses) {
            $children = $children->exclude('ClassName', $hiddenClasses);
        }

        $this->prepopulateTreeData($children);
        $nodes = [];
        foreach ($children as $child) {
            if ($this->canListTreeNode($child)) {
                $data = $this->getTreeNodeData($child, $parent);
                $this->extend('updateTreeNodeData', $data, $child, $parent);
                $nodes[] = $data;
            }
        }
        return $this->jsonSuccess(200, ['nodes' => $nodes]);
    }

    /**
     * Respond to `path/{id}` with the IDs of the ancestors of a record, top level first.
     *
     * An ID of 0 gets a 400 error, because the top level has no path.
     */
    public function apiReadPath(HTTPRequest $request): HTTPResponse
    {
        $this->requireGetRequest($request);
        $id = $this->getTreeRequestID($request, 'ID');
        if ($id === 0) {
            $this->jsonError(400);
        }
        /** @var DataObject&Hierarchy $record */
        $record = $this->getTreeRecord($id);
        // This check calls canView(), not canListTreeNode(), because the React tree asks only for the path of the
        // record that the edit form shows.
        if (!$record->canView()) {
            $this->jsonError(403);
        }
        $ancestorIDs = array_map('intval', $record->getAncestors()->column('ID'));
        return $this->jsonSuccess(200, ['path' => array_reverse($ancestorIDs)]);
    }

    /**
     * Check access as CMSMain does, so the permission codes of CMSMain and an alternateAccessCheck extension on CMSMain
     * apply to the tree too.
     */
    public function canView($member = null)
    {
        return $this->getCMSMain()->canView($member);
    }

    /**
     * Check whether the controller lists a record and reads the children of the record.
     *
     * The controller lists every page, including a page that the member cannot view. The legacy tree shows these pages
     * as disabled rows, so a member can still reach a viewable page below a disabled row. TreeView shows them the same
     * way, from the `canView` value of each node.
     */
    protected function canListTreeNode(DataObject $node): bool
    {
        return true;
    }

    /**
     * Get the fields of a node.
     *
     * The updateTreeNodeData(array &$data, DataObject $node, DataObject $parent) extension hook runs after this method.
     * Each node has these fields:
     * - `id`, `parentId` (int).
     * - `treeTitle` (string) the tree title as plain text.
     * - `tooltip` (string) the record type and the title.
     * - `recordClass` (string).
     * - `classes` (string) the class names from CMSMain::getTreeNodeClasses().
     * - `iconClasses` (string) the class names from CMSMain::getRecordTreeIconClasses().
     * - `statusFlags` (array) a list of `{key, text, title}`. The `title` of a flag can be null.
     * - `hasChildren` (bool) true when the record has children that the tree lists.
     * - `limited` (bool) true when the record has too many children to list.
     * - `listViewUrl` (string|null) the list view of the children, for a record with children.
     * - `editUrl` (string) the edit form, with the search of the current request.
     * - `canView`, `canEdit`, `canAddChildren`, `canDuplicate` (bool).
     *
     * The `fetchChildren` prop of the TreeView component in admin lists the fields that the component consumes.
     *
     * @param DataObject $parent The record whose children the request reads, or a new record at the top level.
     */
    protected function getTreeNodeData(DataObject $node, DataObject $parent): array
    {
        $data = $this->getHierarchyNodeData($node);
        $cmsMain = $this->getCMSMain();
        $data['tooltip'] = _t(
            CMSMain::class . '.RECORD_TYPE_TITLE',
            '(Record type: {type}) {title}',
            [
                'type' => $node->i18n_singular_name(),
                'title' => $node->Title,
            ]
        );
        $data['iconClasses'] = implode(' ', array_filter($cmsMain->getRecordTreeIconClasses($node)));
        $data['listViewUrl'] = $data['hasChildren'] ? $cmsMain->LinkListViewChildren($node->ID) : null;
        $data['editUrl'] = $cmsMain->LinkRecordEdit($node->ID);
        // CMSMain::duplicate() and CMSMain::duplicatewithchildren() make the same check.
        $data['canDuplicate'] = (bool) $node->canCreate(null, ['Parent' => $parent]);
        return $data;
    }

    /**
     * Get the class names of a node from CMSMain::getTreeNodeClasses().
     *
     * A subclass of CMSMain can override CMSMain::getTreeNodeClasses(), and this method returns the classes of that
     * override.
     */
    protected function getTreeNodeClasses(DataObject $node): string
    {
        return $this->getCMSMain()->getTreeNodeClasses($node);
    }

    /**
     * Get the class of the records in the tree, from CMSMain.
     *
     * @return class-string<DataObject>
     */
    protected function getModelClass(): string
    {
        return $this->getCMSMain()->getModelClass();
    }

    /**
     * Get the fields of a node, with null in the fields that getTreeNodeData() fills from CMSMain.
     *
     * getTreeNodeData() also replaces `editUrl` with the link from CMSMain.
     */
    private function getHierarchyNodeData(DataObject $node): array
    {
        /** @var DataObject&Hierarchy $node */
        return [
            'id' => (int) $node->ID,
            'parentId' => (int) $node->ParentID,
            'treeTitle' => $this->getTreeTitleText($node),
            'tooltip' => null,
            'recordClass' => $node->ClassName,
            'classes' => $this->getTreeNodeClasses($node),
            'iconClasses' => null,
            'statusFlags' => $this->getTreeStatusFlags($node),
            'hasChildren' => $this->countTreeChildren($node) > 0,
            'limited' => $this->isTreeNodeLimited($node),
            'listViewUrl' => null,
            'editUrl' => $node->getCMSEditLink(),
            'canView' => (bool) $node->canView(),
            'canEdit' => (bool) $node->canEdit(),
            'canAddChildren' => (bool) $node->canAddChildren(),
        ];
    }

    /**
     * Get a CMSMain for the page fields, with the request of this controller.
     *
     * CMSMain::LinkWithSearch() reads the search from the request, so the edit links keep the search of the tree
     * request.
     */
    private function getCMSMain(): CMSMain
    {
        if (!$this->cmsMain) {
            $this->cmsMain = CMSMain::create();
            $this->cmsMain->setRequest($this->getRequest());
        }
        return $this->cmsMain;
    }

    /**
     * End the request with a 405 error unless it is a GET request.
     *
     * The default action route of RequestHandler reaches both API methods with any HTTP verb, so the GET routes in
     * url_handlers do not limit them.
     */
    private function requireGetRequest(HTTPRequest $request): void
    {
        if (!$request->isGET()) {
            $this->httpError(405);
        }
    }

    /**
     * Get an ID from the URL, or end the request with a 400 JSON error for an ID that is not a whole number.
     */
    private function getTreeRequestID(HTTPRequest $request, string $name): int
    {
        $value = $request->param($name);
        if (!is_string($value) || !ctype_digit($value)) {
            $this->jsonError(400);
        }
        return (int) $value;
    }

    /**
     * Get a record in the current stage, or end the request with a 404 JSON error.
     */
    private function getTreeRecord(int $id): DataObject
    {
        $record = DataObject::get($this->getModelClass())->byID($id);
        if (!$record) {
            $this->jsonError(404);
        }
        return $record;
    }

    /**
     * Check whether a node has too many children to list.
     *
     * The limit is the node_threshold_leaf of the node. MarkedSet::isNodeLimited() reads node_threshold_leaf from the
     * tree root instead.
     */
    private function isTreeNodeLimited(DataObject $node): bool
    {
        $isTopLevel = !$node->ID;
        if ($isTopLevel) {
            return false;
        }
        $threshold = $node->config()->get('node_threshold_leaf');
        return $this->countTreeChildren($node) > $threshold;
    }

    /**
     * Count the children of a record that the tree lists.
     *
     * Hierarchy::numChildren() leaves out the hide_from_cms_tree classes only in the tree actions of LeftAndMain.
     * For a record with hidden classes, this method counts the children without those classes instead.
     */
    private function countTreeChildren(DataObject $node): int
    {
        /** @var DataObject&Hierarchy $node */
        if (!$node->config()->get('hide_from_cms_tree')) {
            return $node->numChildren();
        }
        if (!array_key_exists($node->ID, $this->treeChildCounts)) {
            $this->prepopulateTreeChildCounts([$node]);
        }
        return $this->treeChildCounts[$node->ID];
    }

    /**
     * Count the children without the hide_from_cms_tree classes, with one query for each list of hidden classes.
     */
    private function prepopulateTreeChildCounts(iterable $nodes): void
    {
        $groups = [];
        foreach ($nodes as $node) {
            $hiddenClasses = (array) $node->config()->get('hide_from_cms_tree');
            if ($hiddenClasses) {
                $key = implode(',', $hiddenClasses);
                $groups[$key]['hiddenClasses'] = $hiddenClasses;
                $groups[$key]['ids'][] = (int) $node->ID;
            }
        }
        /** @var DataObject&Hierarchy $singleton */
        $singleton = DataObject::singleton($this->getModelClass());
        foreach ($groups as $group) {
            $parentIDs = $singleton->stageChildren(true, true)
                ->filter('ParentID', $group['ids'])
                ->exclude('ClassName', $group['hiddenClasses'])
                ->column('ParentID');
            $counts = array_count_values($parentIDs);
            foreach ($group['ids'] as $id) {
                $this->treeChildCounts[$id] = $counts[$id] ?? 0;
            }
        }
    }

    /**
     * Fill the caches of the child counts, the version numbers and the edit permissions, so these run no query for each
     * child.
     *
     * A view check of a page with its own viewer groups still runs queries for each child.
     */
    private function prepopulateTreeData(SS_List $children): void
    {
        $ids = $children->column('ID');
        if (empty($ids)) {
            return;
        }
        $this->prepopulateTreeChildCounts($children);
        /** @var DataObject&Hierarchy $singleton */
        $singleton = DataObject::singleton($this->getModelClass());
        $baseClass = $singleton->getHierarchyBaseClass();
        $singleton->prepopulateTreeDataCache($ids, [
            'childrenMethod' => $baseClass::config()->get('tree_children_method'),
            'numChildrenMethod' => 'numChildren',
        ]);
        // This call counts the children again for the whole table and marks the cache complete. A count for $ids alone
        // caches nothing for a childless record, so each childless record would run a query of its own.
        Hierarchy::prepopulate_numchildren_cache($baseClass);
        if (is_a($this->getModelClass(), PermissionCheckable::class, true)) {
            $checker = $singleton->getPermissionChecker();
            if ($checker instanceof InheritedPermissions) {
                $checker->prePopulatePermissionCache(InheritedPermissions::EDIT, $ids);
            }
        }
    }

    /**
     * Get the tree title as plain text.
     *
     * getTreeTitle() can return escaped text or markup.
     */
    private function getTreeTitleText(DataObject $node): string
    {
        /** @var DataObject&Hierarchy $node */
        $title = Convert::xml2raw(strip_tags($node->getTreeTitle()));
        $title = trim(str_replace(["\n", "\r"], '', $title));
        if ($title === '') {
            return _t(CMSMain::class . '.TREE_NO_TITLE', '(no title)');
        }
        return $title;
    }

    private function getTreeStatusFlags(DataObject $node): array
    {
        $flags = [];
        foreach ($node->getStatusFlags() as $key => $flag) {
            if (is_string($flag)) {
                $flag = ['text' => $flag];
            }
            $flags[] = [
                'key' => (string) $key,
                'text' => $flag['text'],
                'title' => $flag['title'] ?? null,
            ];
        }
        return $flags;
    }
}
