<?php

namespace SilverStripe\CMS\Controllers;

use SilverStripe\Admin\HierarchyTreeHandler;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\DataObject;

/**
 * Serves the page tree for CMSMain. Pages the member cannot view are listed as disabled rows, as the legacy tree
 * lists them, and their children are served.
 *
 * Adds `canDuplicate` (bool) to each node, and fills `tooltip`, `iconClasses`, `listViewUrl` and an `editUrl`
 * that keeps the current search.
 */
class CMSMainTreeHandler extends HierarchyTreeHandler
{
    protected function canListTreeNode(DataObject $node): bool
    {
        return true;
    }

    protected function getTreeNodeData(DataObject $node, DataObject $parent): array
    {
        $controller = $this->getCMSMain();
        $data = parent::getTreeNodeData($node, $parent);
        $data['tooltip'] = _t(
            CMSMain::class . '.RECORD_TYPE_TITLE',
            '(Record type: {type}) {title}',
            [
                'type' => $node->i18n_singular_name(),
                'title' => $node->Title,
            ]
        );
        $data['iconClasses'] = implode(' ', array_filter($this->getTreeIconClasses($node)));
        $data['listViewUrl'] = $data['hasChildren'] ? $controller->LinkListViewChildren($node->ID) : null;
        $data['editUrl'] = $controller->LinkRecordEdit($node->ID);
        // The same check as in CMSMain::duplicate() and CMSMain::duplicatewithchildren()
        $data['canDuplicate'] = (bool) $node->canCreate(null, ['Parent' => $parent]);
        return $data;
    }

    protected function getTreeNodeClasses(DataObject $node): string
    {
        return $this->getCMSMain()->getTreeNodeClasses($node);
    }

    // Keep in step with the icon classes in CMSMain::getRecordTreeMarkup()
    private function getTreeIconClasses(DataObject $node): array
    {
        $iconClasses = [
            'jstree-recordicon',
            'record-icon',
            $this->getCMSMain()->getRecordIconCssClass($node),
            'class-' . Convert::raw2htmlid(get_class($node)),
        ];
        $node->invokeWithExtensions('updateTreeIconClasses', $iconClasses);
        return $iconClasses;
    }

    private function getCMSMain(): CMSMain
    {
        /** @var CMSMain $controller */
        $controller = $this->parentController;
        return $controller;
    }
}
