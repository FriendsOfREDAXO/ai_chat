(function () {
    function initStatisticsPage() {
        const form = document.getElementById('klxmchat-stats-period-form');
        const select = document.getElementById('days');

        if (!form || !select) {
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const url = new URL(form.action, window.location.href);
            url.searchParams.set('page', 'ai_chat/statistics');
            url.searchParams.set('days', select.value);
            window.location.href = url.toString();
        });

        select.addEventListener('change', function () {
            form.requestSubmit();
        });
    }

    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('rex:ready', function () {
            initStatisticsPage();
        });
    } else {
        document.addEventListener('DOMContentLoaded', initStatisticsPage);
    }
}());
