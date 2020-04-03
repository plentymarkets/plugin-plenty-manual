// Cookie consents
(function ($) {
    if (!ConsentManager)
    {
        return;
    }

    function _setConsent(key, value )
    {
        const groupKey = key.split(".")[0];
        const consentKey = key.split(".")[1];

        consents[groupKey] = consents[groupKey] || {};
        if (consentKey === "*")
        {
            Object.keys(consents[groupKey]).forEach(cKey =>
            {
                consents[groupKey][cKey] = value;
            });
        }
        else
        {
            consents[groupKey][consentKey] = value;
        }
    }

    function _acceptAll() {
        Object.keys((consents || {})).forEach(groupKey =>
        {
            Object.keys(consents[groupKey]).forEach(consentKey =>
            {
                if (groupKey === "_id")
                {
                    return;
                }
                consents[groupKey] = consents[groupKey] || {};
                consents[groupKey][consentKey] = true;
            });
        });

        ConsentManager.setResponse(consents);
        _updateCheckboxes();
    }

    function _saveConsents() {
        ConsentManager.setResponse(consents);
        _updateCheckboxes();
    }

    function _isConsented(key) {
        const groupKey = key.split(".")[0];
        const consentKey = key.split(".")[1];

        if (consentKey === "*")
        {
            return Object.keys(consents[groupKey] || {}).some(consentKey =>
            {
                return (consents[groupKey] || {})[consentKey];
            });
        }

        return (consents[groupKey] || {})[consentKey];
    }

    function _updateCheckboxes() {
        document.querySelectorAll(".consent-banner input[type=checkbox]")
            .forEach(checkbox =>
            {
                if (checkbox.dataset.consentKey)
                {
                    checkbox.checked = _isConsented(checkbox.dataset.consentKey);
                }
            });
    }

    var consents    = ConsentManager.getConsents();
    var hasResponse = ConsentManager.hasResponse();

    $(document).ready(function () {
        _updateCheckboxes();

        if(!hasResponse) {
            $('.consent-banner').show();
        }

        // Add Listeners
        $('#consent-footer-link').on('click', function() {
            $('.consent-banner').show();
        });

        $('.consent-banner input[type=checkbox]').on('click', function() {
            _setConsent($(this).data('consentKey'), this.checked);
        });

        $('#consent-details-open').on('click', function() {
            $(this).hide();
            $('#consent-details-close').show();
            $('.consent-details').show();
        });

        $('#consent-details-close').on('click', function() {
            $(this).hide();
            $('#consent-details-open').show();
            $('.consent-details').hide();
        });

        $('#consent-allow-all').on('click', function() {
            _acceptAll();
            $('.consent-banner').hide();
        });

        $('#consent-save').on('click', function() {
            _saveConsents();
            $('.consent-banner').hide();
        });
    });
}(jQuery));
