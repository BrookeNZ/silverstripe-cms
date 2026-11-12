<?php

namespace SilverStripe\CMS\Tests\Controllers;

use ReflectionProperty;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Tests\Controllers\CMSMainTreeApiTest\TestHiddenFromTreePage;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\CliDebugView;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\Connect\Database;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

class CMSMainTreeApiTest extends FunctionalTest
{
    protected static $fixture_file = 'CMSMainTreeApiTest.yml';

    protected static $extraDataObjects = [
        TestHiddenFromTreePage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The tree children cache is static and outlives the fixtures of earlier tests
        foreach (['children_for_tree_ids_cache', 'children_for_tree_obj_cache'] as $property) {
            (new ReflectionProperty(Hierarchy::class, $property))->setValue(null, []);
        }

        foreach (SiteTree::get()->exclude('URLSegment', 'history') as $page) {
            $page->publishSingle();
        }
        $contact = $this->objFromFixture(SiteTree::class, 'contact');
        $contact->Content = '<p>Changed in draft</p>';
        $contact->write();
    }

    public function testTopLevelChildren(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $nodes = $this->getJson('admin/pages/api/readTreeChildren/0');

        $this->assertSame($this->idsOf(['home', 'about', 'news', 'contact']), array_column($nodes, 'id'));
        $about = $this->findNode($nodes, 'about');
        $this->assertSame(
            [
                'id',
                'parentId',
                'treeTitle',
                'tooltip',
                'recordClass',
                'classes',
                'iconClasses',
                'statusFlags',
                'hasChildren',
                'limited',
                'listViewUrl',
                'editUrl',
                'canEdit',
                'canAddChildren',
                'canDuplicate',
            ],
            array_keys($about)
        );
        $this->assertSame(0, $about['parentId']);
        $this->assertSame('About us', $about['treeTitle']);
        $this->assertSame('(Record type: Page) About us', $about['tooltip']);
        $this->assertSame(SiteTree::class, $about['recordClass']);
        $this->assertStringStartsWith('class-SilverStripe_CMS_Model_SiteTree', $about['classes']);
        $this->assertStringContainsString('jstree-recordicon record-icon', $about['iconClasses']);
        $this->assertSame([], $about['statusFlags']);
        $this->assertTrue($about['hasChildren']);
        $this->assertFalse($about['limited']);
        $this->assertSame('admin/pages?ParentID=' . $about['id'], $about['listViewUrl']);
        $this->assertSame('admin/pages/edit/show/' . $about['id'], $about['editUrl']);

        $home = $this->findNode($nodes, 'home');
        $this->assertFalse($home['hasChildren']);
        $this->assertNull($home['listViewUrl']);
        $this->assertSame('Contact', $this->findNode($nodes, 'contact')['treeTitle']);
    }

    public function testNestedParent(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $about = $this->objFromFixture(SiteTree::class, 'about');

        $nodes = $this->getJson('admin/pages/api/readTreeChildren/' . $about->ID);

        $this->assertSame($this->idsOf(['team', 'history']), array_column($nodes, 'id'));
        $this->assertSame([$about->ID, $about->ID], array_column($nodes, 'parentId'));
        $this->assertTrue($this->findNode($nodes, 'team')['hasChildren']);
        $this->assertFalse($this->findNode($nodes, 'history')['hasChildren']);
    }

    public function testLeafHasNoChildren(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $jane = $this->objFromFixture(SiteTree::class, 'jane');

        $this->assertSame([], $this->getJson('admin/pages/api/readTreeChildren/' . $jane->ID));
    }

    public function testParentOverTheLimitIsMarkedAndSendsNoChildren(): void
    {
        Config::modify()->set(SiteTree::class, 'node_threshold_leaf', 2);
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $news = $this->objFromFixture(SiteTree::class, 'news');

        $nodes = $this->getJson('admin/pages/api/readTreeChildren/0');
        $newsNode = $this->findNode($nodes, 'news');
        $this->assertTrue($newsNode['limited']);
        $this->assertTrue($newsNode['hasChildren']);
        $this->assertSame('admin/pages?ParentID=' . $news->ID, $newsNode['listViewUrl']);
        $this->assertFalse($this->findNode($nodes, 'about')['limited'], 'Two children is not over a limit of two');

        $this->assertSame([], $this->getJson('admin/pages/api/readTreeChildren/' . $news->ID));
    }

    public function testStatusFlags(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $about = $this->objFromFixture(SiteTree::class, 'about');

        $history = $this->findNode($this->getJson('admin/pages/api/readTreeChildren/' . $about->ID), 'history');
        $this->assertSame(
            [['key' => 'addedtodraft', 'text' => 'Draft', 'title' => 'Item has not been published yet']],
            $history['statusFlags']
        );
        $this->assertStringContainsString(' status-addedtodraft', $history['classes']);

        $contact = $this->findNode($this->getJson('admin/pages/api/readTreeChildren/0'), 'contact');
        $this->assertSame(
            [['key' => 'modified', 'text' => 'Modified', 'title' => 'Item has unpublished changes']],
            $contact['statusFlags']
        );
        $this->assertStringContainsString(' status-modified', $contact['classes']);
    }

    public function testPageTheMemberCannotViewIsListedAsDisabled(): void
    {
        $team = $this->objFromFixture(SiteTree::class, 'team');
        $url = 'admin/pages/api/readTreeChildren/' . $team->ID;

        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $nodes = $this->getJson($url);
        $this->assertSame($this->idsOf(['jane', 'private']), array_column($nodes, 'id'));
        $private = $this->findNode($nodes, 'private');
        $this->assertContains('disabled', explode(' ', $private['classes']));
        $this->assertSame('Private profile', $private['treeTitle']);
        $this->assertSame('(Record type: Page) Private profile', $private['tooltip']);
        $this->assertSame('admin/pages/edit/show/' . $private['id'], $private['editUrl']);
        $this->assertTrue($private['hasChildren']);
        $this->assertFalse($private['limited']);
        $this->assertSame([], $private['statusFlags']);
        $this->assertNotContains('disabled', explode(' ', $this->findNode($nodes, 'jane')['classes']));

        $this->logInWithPermission('ADMIN');
        $private = $this->findNode($this->getJson($url), 'private');
        $this->assertNotContains('disabled', explode(' ', $private['classes']));
    }

    public function testPermissionFlags(): void
    {
        $about = $this->objFromFixture(SiteTree::class, 'about');
        $team = $this->objFromFixture(SiteTree::class, 'team');

        $this->logInWithPermission('ADMIN');
        $admin = $this->getJson('admin/pages/api/readTreeChildren/' . $team->ID);
        foreach (['jane', 'private'] as $name) {
            $this->assertTrue($this->findNode($admin, $name)['canEdit']);
            $this->assertTrue($this->findNode($admin, $name)['canAddChildren']);
            $this->assertTrue($this->findNode($admin, $name)['canDuplicate']);
        }

        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $jane = $this->findNode($this->getJson('admin/pages/api/readTreeChildren/' . $team->ID), 'jane');
        $this->assertTrue($jane['canEdit']);
        $this->assertTrue($jane['canAddChildren']);
        $this->assertTrue($jane['canDuplicate']);

        $history = $this->findNode($this->getJson('admin/pages/api/readTreeChildren/' . $about->ID), 'history');
        $this->assertContains('edit-disabled', explode(' ', $history['classes']));
        $this->assertFalse($history['canEdit']);
        $this->assertFalse($history['canAddChildren']);
        $this->assertFalse($history['canDuplicate']);

        $private = $this->findNode($this->getJson('admin/pages/api/readTreeChildren/' . $team->ID), 'private');
        $this->assertContains('disabled', explode(' ', $private['classes']));
        $this->assertFalse($private['canEdit']);
        $this->assertFalse($private['canAddChildren']);
        $this->assertFalse($private['canDuplicate']);
    }

    public function testPageUnderAParentTheMemberCannotEditCannotBeDuplicated(): void
    {
        $history = $this->objFromFixture(SiteTree::class, 'history');
        SiteTree::create(['Title' => 'Timeline', 'ParentID' => $history->ID, 'CanEditType' => 'LoggedInUsers'])->write();
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $timeline = $this->getJson('admin/pages/api/readTreeChildren/' . $history->ID)[0];
        $this->assertTrue($timeline['canEdit']);
        $this->assertFalse($timeline['canDuplicate']);
    }

    public function testTopLevelPageCanBeDuplicatedByWhoeverMayCreateTopLevelPages(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $config = SiteConfig::current_site_config();

        $about = $this->findNode($this->getJson('admin/pages/api/readTreeChildren/0'), 'about');
        $this->assertTrue($about['canEdit']);
        $this->assertTrue($about['canDuplicate']);

        $config->CanCreateTopLevelType = 'OnlyTheseUsers';
        $config->write();
        $about = $this->findNode($this->getJson('admin/pages/api/readTreeChildren/0'), 'about');
        $this->assertTrue($about['canEdit']);
        $this->assertFalse($about['canDuplicate']);
    }

    public function testDuplicateFlagAgreesWithTheDuplicateAction(): void
    {
        $history = $this->objFromFixture(SiteTree::class, 'history');
        SiteTree::create(['Title' => 'Timeline', 'ParentID' => $history->ID, 'CanEditType' => 'LoggedInUsers'])->write();
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $nodes = [];
        $parentIDs = [0];
        while ($parentIDs) {
            foreach ($this->getJson('admin/pages/api/readTreeChildren/' . array_shift($parentIDs)) as $node) {
                $nodes[] = $node;
                if ($node['hasChildren']) {
                    $parentIDs[] = $node['id'];
                }
            }
        }
        $flags = array_column($nodes, 'canDuplicate');
        $this->assertContains(true, $flags);
        $this->assertContains(false, $flags);

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

    public function testPageTypeThatCannotHaveChildren(): void
    {
        Config::modify()->set(TestHiddenFromTreePage::class, 'allowed_children', 'none');
        $this->logInWithPermission('ADMIN');
        $contact = $this->objFromFixture(SiteTree::class, 'contact');
        TestHiddenFromTreePage::create(['Title' => 'Leaf', 'ParentID' => $contact->ID])->write();

        $leaf = $this->getJson('admin/pages/api/readTreeChildren/' . $contact->ID)[0];
        $this->assertContains('nochildren', explode(' ', $leaf['classes']));
        $this->assertTrue($leaf['canEdit']);
        $this->assertFalse($leaf['canAddChildren']);
    }

    public function testPermissionFlagsAgreeWithTheClassWords(): void
    {
        $allWords = [];
        foreach (['CMS_ACCESS_CMSMain', 'ADMIN'] as $permission) {
            $this->logInWithPermission($permission);
            $nodes = $this->getJson('admin/pages/api/readTreeChildren/0');
            $seen = [];
            for ($i = 0; $i < count($nodes); $i++) {
                $node = $nodes[$i];
                $seen[] = $node['id'];
                $words = explode(' ', $node['classes']);
                $allWords = array_merge($allWords, $words);
                if (in_array('disabled', $words)) {
                    $this->assertFalse($node['canEdit'] || $node['canAddChildren'], $node['treeTitle']);
                }
                if (in_array('edit-disabled', $words)) {
                    $this->assertFalse($node['canEdit'], $node['treeTitle']);
                }
                if (in_array('nochildren', $words)) {
                    $this->assertFalse($node['canAddChildren'], $node['treeTitle']);
                }
                $this->assertSame($node['hasChildren'], $node['listViewUrl'] !== null, $node['treeTitle']);
                if ($node['hasChildren']) {
                    array_push($nodes, ...$this->getJson('admin/pages/api/readTreeChildren/' . $node['id']));
                }
            }
            $this->assertEqualsCanonicalizing(SiteTree::get()->column('ID'), $seen);
        }
        $this->assertEmpty(array_diff(['disabled', 'edit-disabled', 'nochildren'], $allWords));
    }

    public function testViewableChildOfAParentTheMemberCannotView(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $team = $this->objFromFixture(SiteTree::class, 'team');
        $private = $this->objFromFixture(SiteTree::class, 'private');

        $privateNode = $this->findNode($this->getJson('admin/pages/api/readTreeChildren/' . $team->ID), 'private');
        $this->assertContains('disabled', explode(' ', $privateNode['classes']));

        $nodes = $this->getJson('admin/pages/api/readTreeChildren/' . $private->ID);
        $this->assertSame($this->idsOf(['publicchild']), array_column($nodes, 'id'));
        $this->assertSame('Public profile', $nodes[0]['treeTitle']);
    }

    public function testSiblingsFollowTheSortOrder(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $news = $this->objFromFixture(SiteTree::class, 'news');

        $nodes = $this->getJson('admin/pages/api/readTreeChildren/' . $news->ID);
        $this->assertSame($this->idsOf(['news2', 'news3', 'news1']), array_column($nodes, 'id'));
    }

    public function testClassHiddenFromTheTreeIsNotListed(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $contact = $this->objFromFixture(SiteTree::class, 'contact');
        $hidden = TestHiddenFromTreePage::create(['Title' => 'Hidden', 'ParentID' => $contact->ID]);
        $hidden->write();
        $url = 'admin/pages/api/readTreeChildren/' . $contact->ID;

        $this->assertSame([$hidden->ID], array_column($this->getJson($url), 'id'));

        Config::modify()->set(SiteTree::class, 'hide_from_cms_tree', [TestHiddenFromTreePage::class]);
        $this->assertSame([], $this->getJson($url));
    }

    public function testTitleWithSpecialCharactersIsPlainText(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $title = '<b>Bold</b> "q" & \'a\' </script> &amp; a < b ünï';
        $home = $this->objFromFixture(SiteTree::class, 'home');
        SiteTree::create(['Title' => $title, 'ParentID' => $home->ID])->write();

        $response = $this->ajaxGet('admin/pages/api/readTreeChildren/' . $home->ID);
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $nodes = json_decode($response->getBody(), true);
        $this->assertSame($title, $nodes[0]['treeTitle']);
        $this->assertSame('(Record type: Page) ' . $title, $nodes[0]['tooltip']);
    }

    public function testArchivedAndLiveOnlyRecordsAreNotFound(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $archived = $this->objFromFixture(SiteTree::class, 'jane');
        $archived->doArchive();
        $liveOnly = $this->objFromFixture(SiteTree::class, 'news1');
        $liveOnly->deleteFromStage(Versioned::DRAFT);

        foreach ([$archived->ID, $liveOnly->ID] as $id) {
            $this->assertJsonError(404, $this->ajaxGet('admin/pages/api/readTreeChildren/' . $id));
            $this->assertJsonError(404, $this->ajaxGet('admin/pages/api/readTreePath/' . $id));
        }
    }

    public function testInvalidParentID(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');

        $this->assertJsonError(404, $this->ajaxGet('admin/pages/api/readTreeChildren/999999'));
        $this->assertJsonError(400, $this->ajaxGet('admin/pages/api/readTreeChildren/abc'));
        $this->assertJsonError(400, $this->ajaxGet('admin/pages/api/readTreeChildren/-1'));
        $this->assertJsonError(400, $this->ajaxGet('admin/pages/api/readTreeChildren'));
    }

    public function testNoAccessToTheSection(): void
    {
        $this->logInWithPermission('CMS_ACCESS_AssetAdmin');
        $this->assertSame(403, $this->ajaxGet('admin/pages/api/readTreeChildren/0')->getStatusCode());
        $this->assertSame(403, $this->ajaxGet('admin/pages/api/readTreePath/1')->getStatusCode());

        $this->logOut();
        $this->assertSame(403, $this->ajaxGet('admin/pages/api/readTreeChildren/0')->getStatusCode());
    }

    public function testOnlyGetIsAllowed(): void
    {
        $this->logInWithPermission('ADMIN');

        $response = $this->post('admin/pages/api/readTreeChildren/0', []);
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('"treeTitle"', (string) $response->getBody());

        $jane = $this->objFromFixture(SiteTree::class, 'jane');
        $this->assertSame(405, $this->post('admin/pages/apiReadTreeChildren/0', [])->getStatusCode());
        $this->assertSame(405, $this->post('admin/pages/apiReadTreePath/' . $jane->ID, [])->getStatusCode());
    }

    public function testPathToADeepPage(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $jane = $this->objFromFixture(SiteTree::class, 'jane');

        $this->assertSame($this->idsOf(['about', 'team']), $this->getJson('admin/pages/api/readTreePath/' . $jane->ID));
    }

    public function testPathToATopLevelPage(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $about = $this->objFromFixture(SiteTree::class, 'about');

        $this->assertSame([], $this->getJson('admin/pages/api/readTreePath/' . $about->ID));
    }

    public function testPathToAnInvalidRecord(): void
    {
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        $private = $this->objFromFixture(SiteTree::class, 'private');

        $this->assertJsonError(404, $this->ajaxGet('admin/pages/api/readTreePath/999999'));
        $this->assertJsonError(400, $this->ajaxGet('admin/pages/api/readTreePath/0'));
        $this->assertJsonError(400, $this->ajaxGet('admin/pages/api/readTreePath/abc'));
        $this->assertJsonError(400, $this->ajaxGet('admin/pages/api/readTreePath'));
        $this->assertJsonError(403, $this->ajaxGet('admin/pages/api/readTreePath/' . $private->ID));
    }

    public function testQueryCountDoesNotGrowWithTheNumberOfChildren(): void
    {
        $contact = $this->objFromFixture(SiteTree::class, 'contact');
        $news = $this->objFromFixture(SiteTree::class, 'news');
        for ($i = 1; $i <= 50; $i++) {
            SiteTree::create(['Title' => "Child $i", 'ParentID' => $contact->ID])->write();
        }
        // Every listed node is a parent, so a query made only for nodes with children is counted too
        $listed = SiteTree::get()->filter('ParentID', [$contact->ID, $news->ID]);
        foreach ($listed->column('ID') as $id) {
            SiteTree::create(['Title' => "Grandchild of $id", 'ParentID' => $id])->write();
        }
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        // Fills the caches every request shares, so both counts start from the same state
        $this->getJson('admin/pages/api/readTreeChildren/0');

        $threeChildren = $this->countQueries('admin/pages/api/readTreeChildren/' . $news->ID);
        $fiftyChildren = $this->countQueries('admin/pages/api/readTreeChildren/' . $contact->ID);
        $this->assertGreaterThan(0, $threeChildren);
        $this->assertSame($threeChildren, $fiftyChildren);
    }

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
        $this->getJson($url . '?showqueries=1');
        Injector::inst()->unregisterNamedObject(CliDebugView::class);
        return $counter->count;
    }

    private function ajaxGet(string $url): HTTPResponse
    {
        return $this->get($url, null, ['X-Requested-With' => 'XMLHttpRequest']);
    }

    private function getJson(string $url): array
    {
        $response = $this->ajaxGet($url);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        return json_decode($response->getBody(), true);
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
}
