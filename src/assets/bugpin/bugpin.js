(function () {
    'use strict';

    var settings = window.BugPinSettings;
    if (!settings || !settings.apiKey || !window.BugPin) {
        return;
    }
    window.BugPin.init(settings);
})();
