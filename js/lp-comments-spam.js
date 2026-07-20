jQuery(function ($) {
    const findButton = $('#lp-find-likely-spam-comments');
    const markButton = $('#lp-mark-likely-spam-comments');
    const results = $('#lp-comments-spam-results');

    if (!findButton.length || !markButton.length) {
        return;
    }

    function setBusy(isBusy) {
        findButton.prop('disabled', isBusy);
        markButton.prop('disabled', isBusy || !markButton.data('hasResults'));
    }

    findButton.on('click', function (event) {
        event.preventDefault();
        setBusy(true);
        results.html('<p>Scanning pending comments…</p>');

        $.post(lpCommentsSpam.ajaxUrl, {
            action: 'lp_find_likely_spam_comments',
            nonce: lpCommentsSpam.nonce,
        }, function (response) {
            if (response && response.success) {
                results.html(response.data.html);
                markButton.data('hasResults', response.data.count > 0);
                markButton.prop('disabled', response.data.count === 0);
            } else {
                results.html('<p class="description">Unable to load likely spam comments.</p>');
                markButton.data('hasResults', false);
                markButton.prop('disabled', true);
            }
        }).fail(function () {
            results.html('<p class="description">Unable to load likely spam comments.</p>');
            markButton.data('hasResults', false);
            markButton.prop('disabled', true);
        }).always(function () {
            setBusy(false);
        });
    });

    markButton.on('click', function (event) {
        event.preventDefault();
        if (!markButton.data('hasResults')) {
            return;
        }

        setBusy(true);
        results.html('<p>Marking matching comments as spam…</p>');

        $.post(lpCommentsSpam.ajaxUrl, {
            action: 'lp_mark_likely_spam_comments',
            nonce: lpCommentsSpam.nonce,
        }, function (response) {
            if (response && response.success) {
                results.html('<p>Marked ' + response.data.count + ' comments as spam.</p>');
                markButton.data('hasResults', false);
                markButton.prop('disabled', true);
                window.location.reload();
            } else {
                results.html('<p class="description">Unable to mark comments as spam.</p>');
            }
        }).fail(function () {
            results.html('<p class="description">Unable to mark comments as spam.</p>');
        }).always(function () {
            setBusy(false);
        });
    });
});
