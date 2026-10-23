<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_courseaudit;

/**
 * Recognises genuine Zoom / Google Meet / Microsoft Teams class links.
 *
 * Pure PHP (no Moodle API or DB access) so it can be unit tested in isolation.
 *
 * Accuracy rules, compared with the old "does the URL contain zoom.us" substring check:
 *  - The hostname is parsed and matched on a domain boundary, so "notzoom.us", "zoom.us.evil.com"
 *    or a "?ref=zoom.us" query string no longer count.
 *  - The path must look like a meeting/join link (e.g. /j/123456789, /my/room, abc-defg-hij), so
 *    help-centre pages, homepages, download pages and recordings are not mistaken for class links.
 *  - Links wrapped by a redirector (Google "url?q=", Outlook Safe Links, etc.) are unwrapped.
 *  - Scheme-less links ("zoom.us/j/123") and links embedded in HTML/plain text are found.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class meeting_link_detector {

    /** @var string Provider key for Zoom. */
    const ZOOM = 'zoom';

    /** @var string Provider key for Google Meet. */
    const MEET = 'meet';

    /** @var string Provider key for Microsoft Teams. */
    const TEAMS = 'teams';

    /** @var string[] Human labels for the evidence trail shown on the edit page. */
    const LABELS = [
        self::ZOOM => 'Zoom',
        self::MEET => 'Google Meet',
        self::TEAMS => 'Microsoft Teams',
    ];

    /** @var int Max redirect-wrapper layers to unwrap. */
    const MAX_UNWRAP = 2;

    /**
     * Finds the first class-meeting link in free text or HTML (a page, label, section summary).
     *
     * @param string|null $text
     * @return array[] List of ['provider' => string, 'url' => string], de-duplicated by URL.
     */
    public static function find_in_text(?string $text): array {
        if ($text === null || $text === '') {
            return [];
        }

        // Entities first: hrefs routinely contain "&amp;", and Moodle stores some content escaped.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $found = [];

        // Zoom's native deep links are not http(s) URLs. Take them first and cut them out of the
        // text, so their "zoom.us/join?confno=..." tail isn't matched a second time below.
        $deeplink = '~\b(?:zoommtg|zoomus)://[^\s"\'<>\]]+~i';
        if (preg_match_all($deeplink, $text, $matches)) {
            foreach ($matches[0] as $candidate) {
                $hit = self::classify($candidate);
                if ($hit !== null) {
                    $found[$hit['url']] = $hit;
                }
            }
            $text = preg_replace($deeplink, ' ', $text);
        }

        // A candidate starts at a domain boundary, optionally with a scheme, and runs to whitespace,
        // a quote, or a tag bracket. The lookbehind stops "msg.co/x" matching as "g.co/x".
        $pattern = '~(?<![a-z0-9.\-@])(?:https?://)?(?:[a-z0-9\-]+\.)*'
            . '(?:zoom\.us|zoomgov\.com|meet\.google\.com|g\.co|teams\.microsoft\.com|teams\.live\.com|'
            . 'google\.com|safelinks\.protection\.outlook\.com)'
            . '(?:[/?#][^\s"\'<>\]]*)?~i';

        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[0] as $candidate) {
                $hit = self::classify($candidate);
                if ($hit !== null) {
                    $found[$hit['url']] = $hit;
                }
            }
        }

        return array_values($found);
    }

    /**
     * Classifies a single URL.
     *
     * @param string|null $url
     * @return array|null ['provider' => string, 'url' => string] or null if not a class-meeting link.
     */
    public static function classify(?string $url): ?array {
        $url = self::normalise($url);
        if ($url === null) {
            return null;
        }

        for ($layer = 0; $layer <= self::MAX_UNWRAP; $layer++) {
            $parts = parse_url($url);
            if ($parts === false || empty($parts['scheme'])) {
                return null;
            }

            $scheme = strtolower($parts['scheme']);
            if ($scheme === 'zoommtg' || $scheme === 'zoomus') {
                // Zoom desktop deep link, e.g. zoommtg://zoom.us/join?confno=123456789
                return !empty($parts['query']) && preg_match('/(^|&)confno=\d{9,11}/i', $parts['query'])
                    ? ['provider' => self::ZOOM, 'url' => $url]
                    : null;
            }
            if (($scheme !== 'http' && $scheme !== 'https') || empty($parts['host'])) {
                return null;
            }

            $host = strtolower(rtrim($parts['host'], '.'));
            $path = $parts['path'] ?? '';
            $query = $parts['query'] ?? '';

            $provider = self::match($host, $path, $query);
            if ($provider !== null) {
                return ['provider' => $provider, 'url' => $url];
            }

            // Not a meeting link itself - maybe a redirector wrapping one.
            $inner = self::unwrap_redirect($host, $path, $query);
            if ($inner === null) {
                return null;
            }
            $url = $inner;
        }

        return null;
    }

    /**
     * Human-readable label for a provider key.
     *
     * @param string $provider
     * @return string
     */
    public static function label(string $provider): string {
        return self::LABELS[$provider] ?? $provider;
    }

    /**
     * Trims and adds a scheme when a lecturer typed a bare link ("zoom.us/j/123").
     *
     * @param string|null $url
     * @return string|null Null when empty.
     */
    protected static function normalise(?string $url): ?string {
        if ($url === null) {
            return null;
        }
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Punctuation that commonly trails a link pasted into a sentence.
        $url = rtrim($url, ".,;:!?)]}'\"");
        if ($url === '') {
            return null;
        }
        if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        return $url;
    }

    /**
     * Matches host + path against each provider's meeting-link shapes.
     *
     * @param string $host Lower-case host.
     * @param string $path
     * @param string $query
     * @return string|null Provider key.
     */
    protected static function match(string $host, string $path, string $query): ?string {
        if (self::host_is($host, 'zoom.us') || self::host_is($host, 'zoomgov.com')) {
            return self::is_zoom_path($path, $query) ? self::ZOOM : null;
        }

        if ($host === 'meet.google.com') {
            // Meeting code (abc-defg-hij), a nickname lookup, or a Workspace-style /lookup/ path.
            $ok = preg_match('~^/(?:[a-z]{3}-[a-z]{4}-[a-z]{3}|lookup/[a-z0-9._\-]+)/?$~i', $path)
                || preg_match('~^/_meet/[a-z0-9\-]+~i', $path);
            return $ok ? self::MEET : null;
        }
        if ($host === 'g.co' && preg_match('~^/meet/[a-z0-9._\-]+/?$~i', $path)) {
            return self::MEET;
        }

        if (self::host_is($host, 'teams.microsoft.com')) {
            $ok = preg_match('~^/l/meetup-join/~i', $path)
                || preg_match('~^/meet/[0-9]+~i', $path)
                || preg_match('~^/l/meeting/~i', $path);
            return $ok ? self::TEAMS : null;
        }
        if ($host === 'teams.live.com' && preg_match('~^/(?:meet/[0-9]+|l/invite/)~i', $path)) {
            return self::TEAMS;
        }

        return null;
    }

    /**
     * Zoom join/meeting/webinar path shapes. Deliberately excludes /rec/ (recordings),
     * /download, /signin, /pricing and bare support/help pages.
     *
     * @param string $path
     * @param string $query
     * @return bool
     */
    protected static function is_zoom_path(string $path, string $query): bool {
        // /j/123456789, /s/123456789 (start), /w/123456789 (webinar), optional vanity prefix.
        if (preg_match('~^/(?:j|s|w)/\d{9,11}/?$~i', $path)) {
            return true;
        }
        // Web client: /wc/join/123456789 or /wc/123456789/start.
        if (preg_match('~^/wc/(?:join/)?\d{9,11}(?:/(?:join|start))?/?$~i', $path)) {
            return true;
        }
        // Personal meeting room: /my/vanityname.
        if (preg_match('~^/my/[a-z0-9._\-]+/?$~i', $path)) {
            return true;
        }
        // Registration pages: /meeting/register/<token>, /webinar/register/<token>, /meeting/<id>.
        if (preg_match('~^/(?:meeting|webinar)/(?:register/[a-z0-9_\-]+|\d{9,11})/?$~i', $path)) {
            return true;
        }
        // Legacy/query form: /join?confno=123456789 .
        if (preg_match('~^/join/?$~i', $path) && preg_match('/(^|&)confno=\d{9,11}/i', $query)) {
            return true;
        }
        return false;
    }

    /**
     * True when $host is $domain or any subdomain of it, on a label boundary.
     *
     * @param string $host
     * @param string $domain
     * @return bool
     */
    protected static function host_is(string $host, string $domain): bool {
        return $host === $domain
            || (strlen($host) > strlen($domain) && substr($host, -(strlen($domain) + 1)) === '.' . $domain);
    }

    /**
     * Extracts the target of a known link-redirect wrapper.
     *
     * @param string $host
     * @param string $path
     * @param string $query
     * @return string|null
     */
    protected static function unwrap_redirect(string $host, string $path, string $query): ?string {
        $isgoogle = self::host_is($host, 'google.com') && $path === '/url';
        $issafelinks = self::host_is($host, 'safelinks.protection.outlook.com');
        if (!$isgoogle && !$issafelinks) {
            return null;
        }

        parse_str($query, $params);
        foreach (['url', 'q', 'u'] as $key) {
            if (!empty($params[$key]) && is_string($params[$key])) {
                return self::normalise($params[$key]);
            }
        }
        return null;
    }
}
