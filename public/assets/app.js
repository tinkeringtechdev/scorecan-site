/* scorecan.com — minimal helpers, no framework. */
(function () {
    "use strict";

    // "All out — use full quota" auto-fill on the score-entry form.
    function wireAllOut(scope) {
        scope.querySelectorAll("[data-allout-target]").forEach(function (cb) {
            var oversInput = scope.querySelector("#" + cb.dataset.alloutTarget);
            if (!oversInput) return;
            cb.addEventListener("change", function () {
                if (cb.checked) {
                    oversInput.value = oversInput.dataset.fullQuota || "5.0";
                    oversInput.readOnly = true;
                } else {
                    oversInput.readOnly = false;
                }
            });
            // Restore state on load
            if (cb.checked) oversInput.readOnly = true;
        });
    }

    // Auto-suggest winner on the score-entry form based on runs comparison.
    function wireWinnerSuggest(scope) {
        var hr = scope.querySelector("#home_runs"),
            ar = scope.querySelector("#away_runs"),
            ht = scope.querySelector("#home_team_id"),
            at = scope.querySelector("#away_team_id"),
            wn = scope.querySelector("#winner_team_id"),
            tieCb = scope.querySelector("#is_tie");
        if (!hr || !ar || !wn) return;
        function recompute() {
            if (tieCb && tieCb.checked) { wn.value = ""; wn.disabled = true; return; }
            wn.disabled = false;
            var h = parseInt(hr.value || "0", 10),
                a = parseInt(ar.value || "0", 10);
            if (h > a && ht) wn.value = ht.value;
            else if (a > h && at) wn.value = at.value;
        }
        [hr, ar, ht, at, tieCb].forEach(function (el) { if (el) el.addEventListener("input", recompute); if (el) el.addEventListener("change", recompute); });
    }

    // Confirm before destructive actions.
    function wireConfirms() {
        document.querySelectorAll("[data-confirm]").forEach(function (el) {
            el.addEventListener("click", function (e) {
                if (!confirm(el.dataset.confirm)) e.preventDefault();
            });
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        wireAllOut(document);
        wireWinnerSuggest(document);
        wireConfirms();
    });
})();
