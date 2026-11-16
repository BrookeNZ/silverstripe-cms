<div
    class="panel panel--padded panel--scrollable flexbox-area-grow fill-height flexbox-display cms-content-view cms-tree-view-sidebar cms-panel-deferred"
    data-url="$LinkTreeViewDeferred"
    data-url-treeview="$LinkTreeViewDeferred"
    data-url-listview="$LinkListViewDeferred"
    data-url-listviewroot="$LinkListViewRoot"
    data-no-ajax="<% if $TreeIsFiltered %>true<% else %>false<% end_if %>"
>
    <% if $TreeIsFiltered %>
        <% include SilverStripe\\CMS\\Controllers\\CMSMain_ListView %>
    <% else %>
        <%-- When the member chooses the list view, client/src/legacy/CMSMain.js loads the list view with ajax in place of this tree view. --%>
        <% include SilverStripe\\CMS\\Controllers\\CMSMain_TreeView %>
    <% end_if %>
</div>
