(function ($) {
  let previousProcessed = null;

  let wbpiInterval = null;

  async function wbpiRefreshImporterBar() {
    try {
      const response = await fetch(
        wbpi_admin_bar.ajax_url + "?action=wbpi_get_live_import_stats",
      );

      const result = await response.json();

      /*
      |--------------------------------------------------------------------------
      | Invalid response
      |--------------------------------------------------------------------------
      */

      if (!result.success || !result.data) {
        stopWBPITracker();

        return;
      }

      /*
      |--------------------------------------------------------------------------
      | Remove loader after completed
      |--------------------------------------------------------------------------
      */

      if (result.data.completed && result.data.hide === true) {
        const existing = document.querySelector(
          "#wp-admin-bar-wbpi-import-progress",
        );

        if (existing) {
          existing.remove();
        }

        stopWBPITracker();

        return;
      }

      /*
      |--------------------------------------------------------------------------
      | Replace HTML
      |--------------------------------------------------------------------------
      */

      const node = document.querySelector(
        "#wp-admin-bar-wbpi-import-progress .ab-item",
      );

      if (!node) {
        stopWBPITracker();

        return;
      }

      node.innerHTML = result.data.html;

      /*
      |--------------------------------------------------------------------------
      | Pulse animation only on actual change
      |--------------------------------------------------------------------------
      */

      if (
        previousProcessed !== null &&
        previousProcessed !== result.data.processed
      ) {
        node.style.transform = "scale(1.02)";

        setTimeout(function () {
          node.style.transform = "";
        }, 180);
      }

      previousProcessed = result.data.processed;

      /*
      |--------------------------------------------------------------------------
      | Stop polling if completed
      |--------------------------------------------------------------------------
      */

      if (result.data.completed) {
        setTimeout(function () {
          stopWBPITracker();
        }, 12000);
      }
    } catch (e) {
      stopWBPITracker();
    }
  }

  function startWBPITracker() {
    if (wbpiInterval) {
      return;
    }

    wbpiRefreshImporterBar();

    wbpiInterval = setInterval(wbpiRefreshImporterBar, 2000);
  }

  function stopWBPITracker() {
    if (wbpiInterval) {
      clearInterval(wbpiInterval);

      wbpiInterval = null;
    }
  }

  /*
  |--------------------------------------------------------------------------
  | Only start if loader exists
  |--------------------------------------------------------------------------
  */

  if (document.querySelector("#wp-admin-bar-wbpi-import-progress")) {
    startWBPITracker();
  }
})(jQuery);
