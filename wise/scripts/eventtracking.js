(function () {
  const eventUrl = {EVENT_API_URL_JSON};
  const clickId = {CLICK_ID_JSON};
  const scrollThresholds = Array.isArray({SCROLL_THRESHOLDS_JSON}) ? {SCROLL_THRESHOLDS_JSON} : [];
  const timeThresholds = Array.isArray({TIME_THRESHOLDS_JSON}) ? {TIME_THRESHOLDS_JSON} : [];

  if (!eventUrl || !clickId) {
    return;
  }

  const fired = new Set();

  function sendEvent(eventName, value) {
    if (!eventName || fired.has(eventName)) {
      return;
    }
    fired.add(eventName);

    const body = new URLSearchParams();
    body.set('clickid', clickId);
    body.set('event', eventName);
    body.set('value', String(value));

    if (navigator.sendBeacon) {
      const blob = new Blob([body.toString()], { type: 'application/x-www-form-urlencoded;charset=UTF-8' });
      navigator.sendBeacon(eventUrl, blob);
      return;
    }

    fetch(eventUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString(),
      keepalive: true,
      credentials: 'same-origin'
    }).catch(function () {});
  }

  function setupScrollTracking() {
    if (!scrollThresholds.length) {
      return;
    }

    const uniqueThresholds = Array.from(new Set(scrollThresholds.map(Number).filter(function (n) {
      return Number.isFinite(n) && n > 0;
    }))).sort(function (a, b) { return a - b; });

    function checkScroll() {
      const doc = document.documentElement;
      const body = document.body;
      const scrollTop = window.scrollY || doc.scrollTop || body.scrollTop || 0;
      const viewportHeight = window.innerHeight || doc.clientHeight || 0;
      const documentHeight = Math.max(
        body.scrollHeight || 0,
        doc.scrollHeight || 0,
        body.offsetHeight || 0,
        doc.offsetHeight || 0,
        doc.clientHeight || 0
      );
      const maxScrollable = Math.max(documentHeight - viewportHeight, 1);
      const percent = ((scrollTop + viewportHeight) / (maxScrollable + viewportHeight)) * 100;

      uniqueThresholds.forEach(function (threshold) {
        if (percent >= threshold) {
          sendEvent('scroll_' + threshold, 1);
        }
      });
    }

    window.addEventListener('scroll', checkScroll, { passive: true });
    window.addEventListener('resize', checkScroll);
    checkScroll();
  }

  function setupTimeTracking() {
    if (!timeThresholds.length) {
      return;
    }

    const uniqueThresholds = Array.from(new Set(timeThresholds.map(Number).filter(function (n) {
      return Number.isFinite(n) && n > 0;
    }))).sort(function (a, b) { return a - b; });
    let visibleSeconds = 0;

    window.setInterval(function () {
      if (document.visibilityState === 'hidden') {
        return;
      }
      visibleSeconds += 1;
      uniqueThresholds.forEach(function (threshold) {
        if (visibleSeconds >= threshold) {
          sendEvent('stay_' + threshold + 's', 1);
        }
      });
    }, 1000);
  }

  setupScrollTracking();
  setupTimeTracking();
})();
