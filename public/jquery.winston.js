(function($) {

    POP = POP || {};
    POP.Winston = POP.Winston || {};

    /**
     * Records that a pageview has occurred for a given test and variation.
     * Either takes in an array of objects (multiple tests) or a single
     * object (individual test).
     */
    POP.Winston.pageview = function(tests) {
        if (POP.Winston.disabled) {
            return true;
        }

        if (!$.isArray(tests)) {
            tests = [tests];
        }

        $.post(
            POP.Winston.endpoints.trackPageview,
            {
                token: POP.Winston.token,
                tests: tests
            },
            function(response, textStatus, jqXHR) {
                console.log(response);
            }
        );
    };

    /**
     * Record that a successful event has taken place.
     */
    POP.Winston.event = function(test_id, variation_id, event) {
        if (POP.Winston.disabled) {
            return true;
        }
        
        // post to server
        $.post(
            POP.Winston.endpoints.trackEvent,
            {
                token: POP.Winston.token,
                test_id: test_id,
                variation_id: variation_id
            },
            function(response, textStatus, jqXHR) {
                console.log(response);
            }
        );
    };

})(jQuery);
