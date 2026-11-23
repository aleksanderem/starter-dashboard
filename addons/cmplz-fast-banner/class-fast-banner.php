<?php
/**
 * Pure logic for the Complianz Fast Banner addon (no WordPress calls — testable with plain `php`).
 */

defined('ABSPATH') || exit;

class Starter_Cmplz_Fast_Banner {

    /** Complianz banner script; its localized `var complianz = {...}` prints before our inline code. */
    const HANDLE = 'cmplz-cookiebanner';

    /**
     * Inline JS run right before complianz.js. When sessionStorage has no cmplz_user_data yet,
     * it stores the site's default consent data, so complianz.js skips the slow geoIP request
     * (/wp-json/complianz/v1/banner) and shows the banner at once.
     *
     * Built only from constants: nothing from the request or the database ends up in it.
     */
    public static function inline_js() {
        return '(function(){try{'
            . "if(typeof complianz==='undefined'||sessionStorage.getItem('cmplz_user_data')){return;}"
            . "sessionStorage.setItem('cmplz_user_data',JSON.stringify({"
            . 'consenttype:complianz.consenttype,'
            . 'region:complianz.region,'
            . 'version:complianz.version,'
            . 'forceEnableStats:false,'
            . 'banner_version:complianz.banner_version'
            . '}));'
            . '}catch(e){}})();';
    }
}
