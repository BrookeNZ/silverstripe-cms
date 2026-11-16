/**
 * This file mounts the `TreeView` component in place of the legacy page tree.
 *
 * The `onadd` handler creates the React root. The `onremove` handler unmounts the React root.
 * jQuery clears the Entwine properties before `onunmatch` runs. Then `onunmatch` cannot find the React root.
 *
 * A full page load adds the `.cms-page-tree` element before `Injector` boots. Then the first mount happens in
 * `onmatch`.
 *
 * `flushSync` renders the rows before the browser paints the new panel.
 */

/* global window, localStorage */
import jQuery from 'jquery';
import React from 'react';
import { flushSync } from 'react-dom';
import { createRoot } from 'react-dom/client';
import { loadComponent } from 'lib/Injector';
import { createTreeProps, isInSidePanel, loadPanelAndReport } from './treeViewRequests';

// The `reloadKey` prop of every mount. A panel load can mount the tree again.
// That tree gets the same `reloadKey` value. Then that tree does not reload.
let reloadKey = 0;

jQuery.entwine('ss', ($) => {
  const showLoginDialog = () => {
    // `.login-dialog` is in the page only while the log-in modal is open. The `.leftandmain__login-dialog` container
    // stays after the modal closes. Admin's client/src/legacy/LeftAndMain.js sets both classes.
    if (!$('.login-dialog').length) {
      $('.cms-container').showLoginDialog();
    }
  };

  $('.js-injector-boot .cms-page-tree').entwine({
    ReactRoot: null,
    Component: null,

    onmatch() {
      this._super();
      this.mountTree();
    },

    onadd() {
      this._super();
      this.mountTree();
    },

    onremove() {
      const root = this.getReactRoot();
      if (root) {
        root.unmount();
        this.setReactRoot(null);
      }
      this._super();
    },

    // `renderTree()` passes the ID of the current page to `TreeView`. A panel load changes the current page.
    'from .cms-container': {
      onafterstatechange() {
        this.renderTree();
      },
    },

    // The legacy tree updated its nodes after each `aftersubmitform` event from a form in `.cms-container`.
    // This handler reloads the tree after the same event.
    'from .cms-container form': {
      onaftersubmitform() {
        reloadKey += 1;
        this.renderTree();
      },
    },

    mountTree() {
      if (this.getReactRoot()) {
        return;
      }
      this.setComponent(loadComponent('TreeView'));
      this.setReactRoot(createRoot(this[0]));
      this.renderTree();
    },

    renderTree() {
      const root = this.getReactRoot();
      if (!root) {
        return;
      }
      const TreeView = this.getComponent();
      const props = createTreeProps({
        getAttribute: (name) => this.attr(name),
        getCurrentId: () => $('.cms-edit-form :input[name=ID]').val(),
        getSearch: () => window.location.search,
        baseUrl: $('base').attr('href') || '',
        loadPanel: (url) => loadPanelAndReport($('.cms-container'), url),
        showListView: (url) => {
          // client/src/legacy/CMSMain.js reads `ss.pages-view-type` to load the list view in place of the tree.
          localStorage.setItem('ss.pages-view-type', 'listview');
          window.location.assign(url);
        },
        showLoginDialog,
        inSidePanel: isInSidePanel(this[0]),
      });

      flushSync(() => {
        root.render(<TreeView {...props} reloadKey={reloadKey} />);
      });
    },
  });
});
