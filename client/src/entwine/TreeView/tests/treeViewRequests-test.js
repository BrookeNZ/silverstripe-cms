/* global jest, test, expect, beforeEach */
import backend from 'lib/Backend';
import { createTreeProps, isInSidePanel, loadPanelAndReport } from '../treeViewRequests';

jest.mock('lib/Backend', () => ({
  __esModule: true,
  default: { get: jest.fn() },
}));

beforeEach(() => {
  backend.get.mockReset();
});

const page = { id: 3, editUrl: 'admin/pages/edit/show/3?stage=Stage', listViewUrl: 'admin/pages?ParentID=3' };

const attributes = {
  'data-tree-id': 'SilverStripe\\CMS\\Model\\SiteTree',
  'data-label': 'Site Page Navigation',
  'data-root-title': '2024',
  'data-url-children': 'admin/pages/tree/children',
  'data-url-path': 'admin/pages/tree/path/',
};

function build(overrides = {}) {
  const options = {
    getAttribute: (name) => attributes[name],
    getCurrentId: () => '12',
    getSearch: () => '?locale=en_NZ',
    baseUrl: '/',
    loadPanel: jest.fn(() => true),
    showListView: jest.fn(),
    showLoginDialog: jest.fn(),
    ...overrides,
  };
  return { props: createTreeProps(options), options };
}

// Create the error that `Backend` rejects with for a response outside 2xx.
function httpError(status, headers = {}) {
  const error = new Error('Forbidden');
  error.response = { status, headers: new Headers(headers) };
  return error;
}

test('createTreeProps reads the data attributes and passes the form ID on', () => {
  expect(build().props).toMatchObject({
    treeId: 'SilverStripe\\CMS\\Model\\SiteTree',
    label: 'Site Page Navigation',
    rootTitle: '2024',
    currentId: '12',
    className: '',
  });
  expect(build({ inSidePanel: true }).props.className).toBe('tree-view--side-panel');
});

test('createTreeProps fetches children and paths as XHR from the URLs the mount element gives', async () => {
  backend.get.mockResolvedValue({ json: () => Promise.resolve({ nodes: [], path: [] }) });
  const { props } = build();

  await props.fetchChildren(0);
  await props.fetchPath(14);

  expect(backend.get.mock.calls).toEqual([
    ['admin/pages/tree/children/0', { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }],
    ['admin/pages/tree/path/14', { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }],
  ]);
});

test('createTreeProps resolves with the list inside each response', async () => {
  const { props } = build();
  backend.get.mockResolvedValueOnce({ json: () => Promise.resolve({ nodes: [{ id: 1 }] }) });
  await expect(props.fetchChildren(0)).resolves.toEqual([{ id: 1 }]);

  backend.get.mockResolvedValueOnce({ json: () => Promise.resolve({ nodes: [], limited: true }) });
  await expect(props.fetchChildren(3)).resolves.toEqual([]);

  backend.get.mockResolvedValueOnce({ json: () => Promise.resolve({ path: [2, 3] }) });
  await expect(props.fetchPath(14)).resolves.toEqual([2, 3]);
});

test('createTreeProps rejects a failed fetch, and opens the log-in dialog only when the session expired', async () => {
  const { props, options } = build();
  backend.get.mockRejectedValueOnce(new Error('Not Found'));
  await expect(props.fetchPath(999)).rejects.toThrow('Not Found');
  expect(options.showLoginDialog).not.toHaveBeenCalled();

  backend.get.mockRejectedValueOnce(httpError(403));
  await expect(props.fetchChildren(0)).rejects.toThrow('Forbidden');
  expect(options.showLoginDialog).not.toHaveBeenCalled();

  backend.get.mockRejectedValueOnce(httpError(403, { 'X-Reauthenticate': '1' }));
  await expect(props.fetchChildren(0)).rejects.toThrow('Forbidden');
  expect(options.showLoginDialog).toHaveBeenCalledTimes(1);
});

test('onOpen loads the page with the current query string in place of the node one', () => {
  const { props, options } = build();
  expect(props.onOpen(page)).toBe(true);
  expect(options.loadPanel).toHaveBeenCalledWith('admin/pages/edit/show/3?locale=en_NZ');

  const noQuery = build({ getSearch: () => '' });
  noQuery.props.onOpen(page);
  expect(noQuery.options.loadPanel).toHaveBeenCalledWith('admin/pages/edit/show/3');
});

test('onOpen reads the open page when clicked and skips it, returning false', () => {
  let formId = '12';
  const { props, options } = build({ getCurrentId: () => formId });
  formId = '3';

  expect(props.onOpen(page)).toBe(false);
  expect(props.onOpen({ id: 4, editUrl: null })).toBe(false);

  expect(options.loadPanel).not.toHaveBeenCalled();
});

test('onOpen returns what loadPanel returns', () => {
  const request = Promise.resolve();
  expect(build({ loadPanel: () => false }).props.onOpen(page)).toBe(false);
  expect(build({ loadPanel: () => request }).props.onOpen(page)).toBe(request);
});

// Create a jQuery-like container.
// The `loadPanel()` of this container starts a state change or not, like the legacy `loadPanel()`.
function makeContainer({ starts = true, request = null, throws = null } = {}) {
  const handlers = new Set();
  return {
    handlers,
    on: jest.fn((name, handler) => handlers.add(handler)),
    off: jest.fn((name, handler) => handlers.delete(handler)),
    loadPanel: jest.fn(() => {
      if (throws) {
        throw throws;
      }
      if (starts) {
        handlers.forEach((handler) => handler());
      }
    }),
    getStateChangeXHR: () => request,
  };
}

test('loadPanelAndReport returns the panel request once a state change starts, true without one, else false', () => {
  const request = { then: () => {} };
  const started = makeContainer({ request });
  expect(loadPanelAndReport(started, 'admin/pages/edit/show/3')).toBe(request);
  expect(started.loadPanel).toHaveBeenCalledWith('admin/pages/edit/show/3');
  expect(started.handlers.size).toBe(0);

  expect(loadPanelAndReport(makeContainer(), 'admin/pages/edit/show/3')).toBe(true);

  const cancelled = makeContainer({ starts: false, request });
  expect(loadPanelAndReport(cancelled, 'admin/pages/edit/show/3')).toBe(false);
  expect(cancelled.handlers.size).toBe(0);
});

test('loadPanelAndReport stops listening when loadPanel throws, and passes the error on', () => {
  const container = makeContainer({ throws: new Error('Broken') });
  expect(() => loadPanelAndReport(container, 'admin/pages/edit/show/3')).toThrow('Broken');
  expect(container.on).toHaveBeenCalledTimes(1);
  expect(container.handlers.size).toBe(0);
});

test('onShowAsList opens the list view URL joined to the base', () => {
  const { props, options } = build();
  props.onShowAsList(page);
  expect(options.showListView).toHaveBeenCalledWith('/admin/pages?ParentID=3');

  const absolute = build({ baseUrl: 'https://example.test/sub/' });
  absolute.props.onShowAsList(page);
  expect(absolute.options.showListView).toHaveBeenCalledWith('https://example.test/sub/admin/pages?ParentID=3');
});

test('isInSidePanel is true only inside the edit form side panel', () => {
  document.body.innerHTML = `
    <div class="cms-content-tools"><div class="panel"><div id="side"></div></div></div>
    <div class="cms-content-fields"><div id="main"></div></div>
  `;
  expect(isInSidePanel(document.getElementById('side'))).toBe(true);
  expect(isInSidePanel(document.getElementById('main'))).toBe(false);
});
