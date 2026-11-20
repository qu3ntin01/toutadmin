(function () {
  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function format(totalSeconds) {
    var h = Math.floor(totalSeconds / 3600);
    var m = Math.floor((totalSeconds % 3600) / 60);
    var s = totalSeconds % 60;
    return pad(h) + ':' + pad(m) + ':' + pad(s);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('live-timer');
    if (!el) return;
    var start = el.dataset.start;
    if (!start) return;

    var startMs = new Date(start).getTime();
    if (Number.isNaN(startMs)) return;

    function tick() {
      var diff = Math.max(0, Date.now() - startMs);
      el.textContent = format(Math.floor(diff / 1000));
    }

    tick();
    setInterval(tick, 1000);
  });
})();
