<?php

namespace SilverStripe\CMS\Tests\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Controllers\CMSMainTreeController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Tests\Controllers\CMSMainTreeControllerTest\DenyAccessExtension;
use SilverStripe\CMS\Tests\Controllers\CMSMainTreeControllerTest\EmptyTreeTitlePage;
use SilverStripe\CMS\Tests\Controllers\CMSMainTreeControllerTest\HiddenFromTreePage;
use SilverStripe\CMS\Tests\Controllers\CMSMainTreeControllerTest\JsonErrorExtension;
use SilverStripe\CMS\Tests\Controllers\CMSMainTreeControllerTest\NoChildrenPage;
use SilverStripe\CMS\Tests\Controllers\CMSMainTreeControllerTest\TreeNodeDataExtension;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\CliDebugView;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\Connect\Database;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

class CMSMainTreeControllerTest extends FunctionalTest
{
    protected static $fixture_file = 'CMSMainTreeControllerTest.yml';

    protected static $extra_dataobjects = [
        NoChildrenPage::class,
        HiddenFromTreePage::class,
        EmptyTreeTitlePage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Hierarchy keeps the caches of tree children in static properties, so these caches keep values from the
        // fixtures of earlier tests.
        foreach (['children_for_tree_ids_cache', 'children_for_tree_obj_cache'] as $property) {
            (new ReflectionProperty(Hierarchy::class, $property))->setValue(null, []);
        }
    }

    public function testApiReadChildrenAtTheTopLevel(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $response = $this->ajaxGet('admin/pages/tree/children/0');
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $nodes = $this->decodeSuccess($response)['nodes'];

        $this->assertSame($this->idsOf(['home', 'parent', 'limitedParent', 'menuTitle']), array_column($nodes, 'id'));
        $parentPage = $this->findNode($nodes, 'parent');
        $this->assertSame('(Record type: Page) Parent', $parentPage['tooltip']);
        $this->assertSame('admin/pages?ParentID=' . $parentPage['id'], $parentPage['listViewUrl']);
        $this->assertSame('admin/pages/edit/show/' . $parentPage['id'], $parentPage['editUrl']);

        $home = $this->findNode($nodes, 'home');
        $this->assertSame(
            'jstree-recordicon record-icon font-icon-page class-SilverStripe_CMS_Model_SiteTree homepage',
            $home['iconClasses']
        );
        $this->assertNull($home['listViewUrl']);
        $this->assertSame('Menu title', $this->findNode($nodes, 'menuTitle')['treeTitle']);
    }

    public function testApiReadChildrenOfALimitedParent(): void
    {
        Config::modify()->set(SiteTree::class, 'node_threshold_leaf', 2);
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $limitedParent = $this->findNode($this->getNodes('admin/pages/tree/children/0'), 'limitedParent');
        $this->assertTrue($limitedParent['limited']);
        $this->assertTrue($limitedParent['hasChildren']);
        $this->assertSame('admin/pages?ParentID=' . $limitedParent['id'], $limitedParent['listViewUrl']);
        $this->assertSame(
            ['nodes' => [], 'limited' => true],
            $this->decodeSuccess($this->ajaxGet('admin/pages/tree/children/' . $limitedParent['id']))
        );

        Config::modify()->set(SiteTree::class, 'node_threshold_leaf', 3);
        $this->assertFalse($this->findNode($this->getNodes('admin/pages/tree/children/0'), 'limitedParent')['limited']);
        $children = $this->decodeSuccess($this->ajaxGet('admin/pages/tree/children/' . $limitedParent['id']));
        $this->assertArrayNotHasKey('limited', $children);
        $this->assertCount(3, $children['nodes']);
    }

    public function testApiReadChildrenWithAnEmptyTreeTitle(): void
    {
        $menuTitlePage = $this->objFromFixture(SiteTree::class, 'menuTitle');
        (new EmptyTreeTitlePage(['Title' => 'Has a title', 'ParentID' => $menuTitlePage->ID]))->write();
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $nodes = $this->getNodes('admin/pages/tree/children/' . $menuTitlePage->ID);

        $this->assertSame('(no title)', $nodes[0]['treeTitle']);
    }

    public static function provideIndex(): array
    {
        return [
            'no action' => ['admin/pages/tree'],
            'index action' => ['admin/pages/tree/index'],
        ];
    }

    #[DataProvider('provideIndex')]
    public function testIndex(string $url): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $this->assertJsonError(404, $this->ajaxGet($url));
    }

    public function testApiReadChildrenLeavesOutHiddenClasses(): void
    {
        Config::modify()->set(SiteTree::class, 'hide_from_cms_tree', [HiddenFromTreePage::class]);
        Config::modify()->set(SiteTree::class, 'node_threshold_leaf', 2);
        $parentPage = $this->objFromFixture(SiteTree::class, 'parent');
        $menuTitlePage = $this->objFromFixture(SiteTree::class, 'menuTitle');
        for ($i = 1; $i <= 3; $i++) {
            (new HiddenFromTreePage(['Title' => "Hidden child $i", 'ParentID' => $parentPage->ID]))->write();
        }
        (new HiddenFromTreePage(['Title' => 'Hidden only child', 'ParentID' => $menuTitlePage->ID]))->write();
        (new HiddenFromTreePage(['Title' => 'Hidden top level', 'Sort' => 5]))->write();
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $nodes = $this->getNodes('admin/pages/tree/children/0');
        $this->assertSame($this->idsOf(['home', 'parent', 'limitedParent', 'menuTitle']), array_column($nodes, 'id'));
        $parentNode = $this->findNode($nodes, 'parent');
        $this->assertTrue($parentNode['hasChildren']);
        $this->assertFalse($parentNode['limited']);
        $menuTitleNode = $this->findNode($nodes, 'menuTitle');
        $this->assertFalse($menuTitleNode['hasChildren']);
        $this->assertNull($menuTitleNode['listViewUrl']);

        $children = $this->decodeSuccess($this->ajaxGet('admin/pages/tree/children/' . $parentPage->ID));
        $this->assertArrayNotHasKey('limited', $children);
        $this->assertSame($this->idsOf(['child', 'cannotEdit']), array_column($children['nodes'], 'id'));
    }

    public static function provideApiReadChildrenErrors(): array
    {
        return [
            'unknown parent' => ['999999', 404],
            'not a number' => ['abc', 400],
            'negative number' => ['-1', 400],
            'no parent' => ['', 400],
        ];
    }

    #[DataProvider('provideApiReadChildrenErrors')]
    public function testApiReadChildrenErrors(string $parentID, int $expectedCode): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $this->assertJsonError($expectedCode, $this->ajaxGet(rtrim('admin/pages/tree/children/' . $parentID, '/')));
    }

    public function testApiReadPath(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $grandchildID = $this->idFromFixture(SiteTree::class, 'grandchild');
        $parentID = $this->idFromFixture(SiteTree::class, 'parent');

        $this->assertSame(
            ['path' => $this->idsOf(['parent', 'child'])],
            $this->decodeSuccess($this->ajaxGet('admin/pages/tree/path/' . $grandchildID))
        );
        $this->assertSame(['path' => []], $this->decodeSuccess($this->ajaxGet('admin/pages/tree/path/' . $parentID)));
    }

    public static function provideApiReadPathErrors(): array
    {
        return [
            'unknown record' => ['999999', 404],
            'top level' => ['0', 400],
            'not a number' => ['abc', 400],
            'no record' => ['', 400],
        ];
    }

    #[DataProvider('provideApiReadPathErrors')]
    public function testApiReadPathErrors(string $id, int $expectedCode): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $this->assertJsonError($expectedCode, $this->ajaxGet(rtrim('admin/pages/tree/path/' . $id, '/')));
    }

    public function testApiReadPathOfAPageTheMemberCannotView(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $cannotViewID = $this->idFromFixture(SiteTree::class, 'cannotView');
        $this->assertJsonError(403, $this->ajaxGet('admin/pages/tree/path/' . $cannotViewID));
    }

    public static function provideApiReadOnlyAllowsGet(): array
    {
        return [
            'children route' => ['admin/pages/tree/children/0', null],
            'children action' => ['admin/pages/tree/apiReadChildren/0', 405],
            'path action' => ['admin/pages/tree/apiReadPath/1', 405],
        ];
    }

    #[DataProvider('provideApiReadOnlyAllowsGet')]
    public function testApiReadOnlyAllowsGet(string $url, ?int $expectedCode): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $response = $this->post($url, []);
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('"treeTitle"', (string) $response->getBody());
        if ($expectedCode) {
            $this->assertSame($expectedCode, $response->getStatusCode());
        }
    }

    public function testJsonErrorRunsTheHooks(): void
    {
        CMSMainTreeController::add_extension(JsonErrorExtension::class);
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $this->assertSame('404', $this->ajaxGet('admin/pages/tree/children/999999')->getHeader('X-Tree-Error'));
        $this->assertSame('400', $this->ajaxGet('admin/pages/tree/path/abc')->getHeader('X-Tree-Error'));
    }

    public function testApiReadChildrenStatusFlags(): void
    {
        $childPage = $this->objFromFixture(SiteTree::class, 'child');
        $childPage->publishSingle();
        $childPage->Content = '<p>Changed in draft</p>';
        $childPage->write();
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $parentPage = $this->objFromFixture(SiteTree::class, 'parent');

        $nodes = $this->getNodes('admin/pages/tree/children/' . $parentPage->ID);
        $cannotEditNode = $this->findNode($nodes, 'cannotEdit');
        $this->assertSame(['addedtodraft'], array_column($cannotEditNode['statusFlags'], 'key'));
        $this->assertContains('status-addedtodraft', $this->words($cannotEditNode));
        $child = $this->findNode($nodes, 'child');
        $this->assertSame(['modified'], array_column($child['statusFlags'], 'key'));
        $this->assertContains('status-modified', $this->words($child));
    }

    public function testApiReadChildrenListsAPageTheMemberCannotView(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $child = $this->objFromFixture(SiteTree::class, 'child');

        $nodes = $this->getNodes('admin/pages/tree/children/' . $child->ID);
        $this->assertSame($this->idsOf(['grandchild', 'cannotView']), array_column($nodes, 'id'));
        $cannotViewNode = $this->findNode($nodes, 'cannotView');
        $this->assertFalse($cannotViewNode['canView']);
        $this->assertContains('disabled', $this->words($cannotViewNode));

        $children = $this->getNodes('admin/pages/tree/children/' . $cannotViewNode['id']);
        $this->assertSame($this->idsOf(['viewableUnderCannotView']), array_column($children, 'id'));
        $this->assertTrue($children[0]['canView']);
    }

    public function testApiReadChildrenEditUrlKeepsTheSearch(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $parentPage = $this->findNode($this->getNodes('admin/pages/tree/children/0?q%5BTerm%5D=Parent'), 'parent');
        $this->assertSame('admin/pages/edit/show/' . $parentPage['id'] . '?q%5BTerm%5D=Parent', $parentPage['editUrl']);
    }

    public function testApiReadChildrenUpdateTreeNodeDataHookSeesThePageFields(): void
    {
        CMSMainTreeController::add_extension(TreeNodeDataExtension::class);
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $parentPage = $this->findNode($this->getNodes('admin/pages/tree/children/0'), 'parent');

        $this->assertSame('(Record type: Page) Parent', $parentPage['tooltipSeen']);
    }

    public function testApiReadChildrenClassesUseTheCMSMainOverride(): void
    {
        $section = new class extends CMSMain {
            public function getTreeNodeClasses(DataObject $node): string
            {
                return parent::getTreeNodeClasses($node) . ' from-section';
            }
        };
        Injector::inst()->load([CMSMain::class => ['class' => get_class($section)]]);
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $home = $this->findNode($this->getNodes('admin/pages/tree/children/0'), 'home');

        $this->assertContains('from-section', explode(' ', $home['classes']));
    }

    public function testApiReadChildrenIconClassesUseTheCMSMainOverride(): void
    {
        $section = new class extends CMSMain {
            public function getRecordTreeIconClasses(DataObject $record): array
            {
                return [...parent::getRecordTreeIconClasses($record), 'from-section'];
            }
        };
        Injector::inst()->load([CMSMain::class => ['class' => get_class($section)]]);
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $home = $this->findNode($this->getNodes('admin/pages/tree/children/0'), 'home');

        $this->assertContains('from-section', explode(' ', $home['iconClasses']));
    }

    public function testApiReadChildrenCanDuplicateAtTheTopLevel(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $config = SiteConfig::current_site_config();

        $parentPage = $this->findNode($this->getNodes('admin/pages/tree/children/0'), 'parent');
        $this->assertTrue($parentPage['canDuplicate']);

        $config->CanCreateTopLevelType = 'OnlyTheseUsers';
        $config->write();
        $parentPage = $this->findNode($this->getNodes('admin/pages/tree/children/0'), 'parent');
        $this->assertTrue($parentPage['canEdit']);
        $this->assertFalse($parentPage['canDuplicate']);
    }

    public function testApiReadChildrenCanDuplicateMatchesTheDuplicateActions(): void
    {
        $cannotEditPage = $this->objFromFixture(SiteTree::class, 'cannotEdit');
        $timelineID = (new SiteTree([
            'Title' => 'Timeline',
            'ParentID' => $cannotEditPage->ID,
            'CanEditType' => 'LoggedInUsers',
        ]))->write();
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $nodes = [];
        $parentIDs = [0];
        while ($parentIDs) {
            foreach ($this->getNodes('admin/pages/tree/children/' . array_shift($parentIDs)) as $node) {
                $nodes[] = $node;
                if ($node['hasChildren']) {
                    $parentIDs[] = $node['id'];
                }
            }
        }
        $flags = array_column($nodes, 'canDuplicate');
        $this->assertContains(true, $flags);
        $this->assertContains(false, $flags);

        $timeline = array_column($nodes, null, 'id')[$timelineID];
        $this->assertTrue($timeline['canEdit']);
        $this->assertFalse($timeline['canDuplicate'], 'A page under a parent the member cannot edit');

        foreach ($nodes as $node) {
            foreach (['duplicate', 'duplicatewithchildren'] as $action) {
                $count = SiteTree::get()->count();
                $this->get("admin/pages/$action/" . $node['id']);
                $this->assertSame(
                    $node['canDuplicate'],
                    SiteTree::get()->count() > $count,
                    "$action {$node['treeTitle']}"
                );
            }
        }
    }

    public function testApiReadChildrenOfAPageTypeWithoutChildren(): void
    {
        $this->logInWithPermission('ADMIN');
        $menuTitlePage = $this->objFromFixture(SiteTree::class, 'menuTitle');
        (new NoChildrenPage(['Title' => 'No children', 'ParentID' => $menuTitlePage->ID]))->write();

        $noChildrenNode = $this->getNodes('admin/pages/tree/children/' . $menuTitlePage->ID)[0];
        $this->assertTrue($noChildrenNode['canEdit']);
        $this->assertFalse($noChildrenNode['canAddChildren']);
    }

    public function testApiReadChildrenTreeTitleIsPlainText(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $title = '<b>Bold</b> "q" & \'a\' </script> &amp; a < b ünï';
        $home = $this->objFromFixture(SiteTree::class, 'home');
        (new SiteTree(['Title' => $title, 'ParentID' => $home->ID]))->write();

        $nodes = $this->getNodes('admin/pages/tree/children/' . $home->ID);
        $this->assertSame($title, $nodes[0]['treeTitle']);
        $this->assertSame('(Record type: Page) ' . $title, $nodes[0]['tooltip']);
    }

    public function testApiReadChildrenOfALiveOnlyPage(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $liveOnly = $this->objFromFixture(SiteTree::class, 'limitedChild1');
        $liveOnly->publishSingle();
        $liveOnly->deleteFromStage(Versioned::DRAFT);

        $this->assertSame(404, $this->ajaxGet('admin/pages/tree/children/' . $liveOnly->ID)->getStatusCode());
    }

    public function testCanViewWithoutAccessToTheSection(): void
    {
        $this->logInWithPermission('CMS_ACCESS_AssetAdmin');
        $this->assertSame(403, $this->ajaxGet('admin/pages/tree/children/0')->getStatusCode());
    }

    public function testCanViewWhenLoggedOut(): void
    {
        $this->logOut();

        $response = $this->ajaxGet('admin/pages/tree/children/0');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('1', $response->getHeader('X-Reauthenticate'));
    }

    public static function provideUrlSegmentLeavesTheOtherPagesRoutesAlone(): array
    {
        return [
            'pages section' => ['admin/pages', null],
            'legacy tree' => ['admin/pages/treeview', null],
            'edit form' => ['admin/pages/edit/show/', 'parent'],
        ];
    }

    #[DataProvider('provideUrlSegmentLeavesTheOtherPagesRoutesAlone')]
    public function testUrlSegmentLeavesTheOtherPagesRoutesAlone(string $url, ?string $fixtureName): void
    {
        $this->logInWithPermission('ADMIN');
        if ($fixtureName) {
            $url .= $this->idFromFixture(SiteTree::class, $fixtureName);
        }

        $response = $this->get($url);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', $response->getHeader('Content-Type'));
    }

    public function testCanViewUsesTheCMSMainAccessCheck(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $this->assertSame(200, $this->ajaxGet('admin/pages/tree/children/0')->getStatusCode());

        CMSMain::add_extension(DenyAccessExtension::class);
        $this->assertSame(403, $this->ajaxGet('admin/pages/tree/children/0')->getStatusCode());
    }

    public static function provideApiReadChildrenQueryCount(): array
    {
        return [
            'without hidden classes' => [[]],
            'with hidden classes' => [[HiddenFromTreePage::class]],
        ];
    }

    #[DataProvider('provideApiReadChildrenQueryCount')]
    public function testApiReadChildrenQueryCount(array $hiddenClasses): void
    {
        Config::modify()->set(SiteTree::class, 'hide_from_cms_tree', $hiddenClasses);
        $menuTitlePage = $this->objFromFixture(SiteTree::class, 'menuTitle');
        $limitedParent = $this->objFromFixture(SiteTree::class, 'limitedParent');
        for ($i = 1; $i <= 50; $i++) {
            (new SiteTree(['Title' => "Child $i", 'ParentID' => $menuTitlePage->ID]))->write();
        }
        // Each listed node gets a child, so the count includes each query that runs only for a node with children.
        $listed = SiteTree::get()->filter('ParentID', [$menuTitlePage->ID, $limitedParent->ID]);
        foreach ($listed->column('ID') as $id) {
            (new SiteTree(['Title' => "Grandchild of $id", 'ParentID' => $id]))->write();
        }
        // The check for the modified flag compares draft and live versions, so the test publishes each listed page
        // and changes one draft in each list.
        foreach ($listed as $page) {
            $page->publishSingle();
        }
        foreach ([$menuTitlePage, $limitedParent] as $parent) {
            $child = $listed->filter('ParentID', $parent->ID)->first();
            $child->Content = '<p>Changed in draft</p>';
            $child->write();
        }
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        // This request fills the caches that every request shares, so both counts start from the same state.
        $this->getNodes('admin/pages/tree/children/0');

        $threeChildren = $this->countQueries('admin/pages/tree/children/' . $limitedParent->ID);
        $fiftyChildren = $this->countQueries('admin/pages/tree/children/' . $menuTitlePage->ID);
        $this->assertGreaterThan(0, $threeChildren);
        $this->assertSame($threeChildren, $fiftyChildren);
    }

    /**
     * Count the database queries of a request.
     *
     * With showqueries, Database::displayQuery() reports each query through CliDebugView, so the CliDebugView subclass
     * below counts each query.
     */
    private function countQueries(string $url): int
    {
        $counter = new class extends CliDebugView {
            public int $count = 0;

            public function renderMessage($message, $caller, $showHeader = true)
            {
                if (is_a($caller['class'] ?? '', Database::class, true) && $caller['function'] === 'displayQuery') {
                    $this->count++;
                    return '';
                }
                return parent::renderMessage($message, $caller, $showHeader);
            }
        };
        Injector::inst()->registerService($counter, CliDebugView::class);
        $this->getNodes($url . '?showqueries=1');
        Injector::inst()->unregisterNamedObject(CliDebugView::class);
        return $counter->count;
    }

    private function ajaxGet(string $url): HTTPResponse
    {
        return $this->get($url, null, ['X-Requested-With' => 'XMLHttpRequest']);
    }

    private function getNodes(string $url): array
    {
        return $this->decodeSuccess($this->ajaxGet($url))['nodes'];
    }

    private function decodeSuccess(HTTPResponse $response): array
    {
        if ($response->getStatusCode() !== 200) {
            $this->fail('Status ' . $response->getStatusCode() . ': ' . $response->getBody());
        }
        return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertJsonError(int $code, HTTPResponse $response): void
    {
        $this->assertSame($code, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $body = json_decode($response->getBody(), true);
        $this->assertSame('error', $body['status']);
        $this->assertSame($code, $body['errors'][0]['code']);
    }

    private function idsOf(array $fixtureNames): array
    {
        return array_map(fn (string $name) => $this->idFromFixture(SiteTree::class, $name), $fixtureNames);
    }

    private function findNode(array $nodes, string $fixtureName): array
    {
        $id = $this->idFromFixture(SiteTree::class, $fixtureName);
        foreach ($nodes as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }
        $this->fail("Node $fixtureName is not in the response");
    }

    private function words(array $node): array
    {
        return explode(' ', $node['classes']);
    }
}
