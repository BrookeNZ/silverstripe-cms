/**
 * This file holds the requests and callbacks of the page tree, apart from TreeViewEntwine.js.
 * The functions in this file run without jQuery.
 *
 * templates/SilverStripe/CMS/Controllers/Includes/CMSMain_TreeView.ss sets the data attributes.
 * The URLs and the responses come from `SilverStripe\CMS\Controllers\CMSMainTreeController`.
 * `CMSMainTreeController::getTreeNodeData()` documents the fields of a node.
 *
 * `loadPanelAndReport()` returns the value that the `onOpen` prop of `TreeView` expects:
 * - `false` when no state change started, for example after the member cancels the unsaved-changes prompt.
 * - The panel request from `getStateChangeXHR()`. The panel request rejects on failure and on abort.
 * - `true` when a state change started without a request.
 */

import backend from 'lib/Backend';
import { joinUrlPaths } from 'lib/urls';

function fetchJson(url, onReauthenticate) {
  // With `X-Requested-With`, an expired session responds 403 with `X-Reauthenticate`, like a jQuery request of the CMS.
  // Without `X-Requested-With`, the response is a redirect to the log-in page.
  return backend.get(url, { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' })
    .then((response) => response.json())
    .catch((error) => {
      // `Backend` rejects with the response in `error.response`.
      if (onReauthenticate && error.response && error.response.headers.get('X-Reauthenticate')) {
        onReauthenticate();
      }
      throw error;
    });
}

// Check whether `element` is in the narrow side panel of the edit form, not in the main area of the Pages section.
export function isInSidePanel(element) {
  return Boolean(element.closest('.cms-content-tools'));
}

/**
 * Load a panel through `container.loadPanel()`. `loadPanel()` returns nothing. The file header lists the return values.
 * `loadPanel()` must trigger `beforestatechange` before `loadPanel()` returns.
 */
export function loadPanelAndReport(container, url) {
  let started = false;
  const handleStart = () => {
    started = true;
  };
  container.on('beforestatechange', handleStart);
  try {
    container.loadPanel(url);
  } finally {
    container.off('beforestatechange', handleStart);
  }
  if (!started) {
    return false;
  }
  const request = typeof container.getStateChangeXHR === 'function' ? container.getStateChangeXHR() : null;
  return request || true;
}

/**
 * Build the `TreeView` props from the attributes of the mount element.
 * The caller passes in the page side effects. Then this function stays free of jQuery and `window`.
 */
export function createTreeProps({
  getAttribute,
  getCurrentId,
  getSearch,
  baseUrl = '',
  loadPanel,
  showListView,
  showLoginDialog = null,
  inSidePanel = false,
}) {
  // Fetch the list in the `key` field of a `CMSMainTreeController` response: `nodes` or `path`.
  const fetchFrom = (attribute, key) => (id) => fetchJson(joinUrlPaths(getAttribute(attribute), String(id)), showLoginDialog)
    .then((body) => body[key]);

  // Open the node like a legacy tree click: drop the query string of `node.editUrl` and add the current query string.
  // Return the result of `loadPanel()`, or `false` when nothing loads.
  const openNode = (node) => {
    if (!node.editUrl || String(node.id) === String(getCurrentId())) {
      return false;
    }
    const url = node.editUrl.split('?')[0];
    const query = getSearch().replace(/^\?/, '');
    return loadPanel(query ? `${url}?${query}` : url);
  };
  const showChildrenAsList = (node) => {
    showListView(joinUrlPaths(baseUrl, node.listViewUrl));
  };

  return {
    treeId: getAttribute('data-tree-id'),
    label: getAttribute('data-label'),
    rootTitle: getAttribute('data-root-title'),
    className: inSidePanel ? 'tree-view--side-panel' : '',
    currentId: getCurrentId(),
    fetchChildren: fetchFrom('data-url-children', 'nodes'),
    fetchPath: fetchFrom('data-url-path', 'path'),
    onOpen: openNode,
    onShowAsList: showChildrenAsList,
  };
}
