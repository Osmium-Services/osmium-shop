(function () {
    'use strict';

    var orderId = orderViewConfig.orderId;
    var endpoint = orderViewConfig.endpoint;

    function post(payload) {
        return fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(Object.assign({ id: orderId, csrf_token: window.csrfToken }, payload)) // validateCsrfJson() reads csrf_token, not token
        }).then(function (response) { return response.json(); });
    }

    /**
     * Reload the order, id and all.
     *
     * Not location.reload(): the ?id= has already been stashed and stripped
     * from the address bar by then, so reloading asks for /view/ with no id
     * and the controller sends you to the list.
     */
    function returnToOrder(extraParams) {
        var url = endpoint.replace(/\/action\/?$/, '/view/') + '?id=' + orderId; // endpoint has no trailing slash when the site's URLs have none
        if (extraParams) url += '&' + extraParams;
        window.location.href = url;
    }

    function feedback(message, isError) {
        document.getElementById('orderFeedback').innerHTML =
            '<div class="alert alert-' + (isError ? 'danger' : 'success') + '">' + message + '</div>';
    }

    // For service panels (order.panels hooks), which render before this script.
    window.osmiumOrder = { id: orderId, post: post, feedback: feedback, returnToOrder: returnToOrder };

    var dispatchBtn = document.getElementById('markDispatchedBtn');
    if (dispatchBtn) {
        dispatchBtn.addEventListener('click', function () {
            post({
                action: 'mark_dispatched',
                tracking_carrier: document.getElementById('tracking_carrier').value,
                tracking_ref: document.getElementById('tracking_ref').value
            }).then(function (data) {
                if (!data.success) return feedback(data.error || 'Could not mark dispatched', true);

                var params = data.email_sent === null ? 'dispatched=1' : 'email_sent=' + (data.email_sent ? '1' : '0'); // null: dispatch emails are switched off in Shop settings
                if (data.email_error) params += '&email_error=' + encodeURIComponent(data.email_error);
                returnToOrder(params);
            });
        });
    }

    var refundBtn = document.getElementById('recordRefundBtn');
    if (refundBtn) {
        refundBtn.addEventListener('click', function () {
            // Deliberately not a confirm() dialog: those block the extension
            // and this is reversible by adding a note, not by losing money.
            post({ action: 'record_refund' }).then(function (data) {
                if (!data.success) return feedback(data.error || 'Could not record the refund', true);
                returnToOrder();
            });
        });
    }

    var noteBtn = document.getElementById('addNoteBtn');
    if (noteBtn) {
        noteBtn.addEventListener('click', function () {
            var input = document.getElementById('noteInput');
            post({ action: 'add_note', note: input.value }).then(function (data) {
                if (!data.success) return feedback(data.error || 'Could not add the note', true);
                returnToOrder();
            });
        });
    }
})();
