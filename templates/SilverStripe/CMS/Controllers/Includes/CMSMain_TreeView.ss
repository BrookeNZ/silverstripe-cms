<% include SilverStripe\\CMS\\Controllers\\CMSMain_ContentToolActions View='Tree' %>

$ExtraTreeTools

<% if $TreeIsFiltered %>
    <div class="cms-tree-filtered cms-notice flexbox-area-grow">
		<strong><%t SilverStripe\CMS\Controllers\CMSMain.TreeFiltered 'Showing search results.' %></strong>
		<a href="javascript:void(0)" class="clear-filter">
			<%t SilverStripe\CMS\Controllers\CMSMain.TreeFilteredClear 'Clear' %>
		</a>
    </div>
<% else %>
    <%-- client/src/entwine/TreeView/TreeViewEntwine.js mounts TreeView on this element. client/src/entwine/TreeView/treeViewRequests.js reads the data attributes. --%>
    <div class="cms-page-tree flexbox-area-grow fill-height"
        data-tree-id="$ModelClass.ATT"
        data-url-children="$LinkTree('children').ATT"
        data-url-path="$LinkTree('path').ATT"
        data-root-title="$CMSTreeTitle.ATT"
        data-label="<%t SilverStripe\CMS\Controllers\CMSMain.SITE_PAGE_NAVIGATION 'Site Page Navigation' %>"
    ></div>
<% end_if %>
