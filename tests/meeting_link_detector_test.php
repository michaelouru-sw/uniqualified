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
 * Unit tests for the Zoom / Google Meet / Teams link recogniser.
 *
 * @package    local_courseaudit
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_courseaudit\meeting_link_detector
 */
final class meeting_link_detector_test extends \basic_testcase {

    /**
     * @dataProvider classify_provider
     */
    public function test_classify(string $url, ?string $expectedprovider): void {
        $result = meeting_link_detector::classify($url);
        $this->assertSame($expectedprovider, $result['provider'] ?? null, $url);
    }

    public static function classify_provider(): array {
        return [
            // Zoom: genuine class links.
            'zoom join' => ['https://zoom.us/j/81234567890', 'zoom'],
            'zoom join with pwd' => ['https://kca-ac-ke.zoom.us/j/81234567890?pwd=abcDEF123', 'zoom'],
            'zoom regional subdomain' => ['https://us02web.zoom.us/j/81234567890', 'zoom'],
            'zoom 9 digit id' => ['https://zoom.us/j/812345678', 'zoom'],
            'zoom bare, no scheme' => ['zoom.us/j/81234567890', 'zoom'],
            'zoom personal room' => ['https://kca.zoom.us/my/dr.otieno', 'zoom'],
            'zoom web client' => ['https://zoom.us/wc/join/81234567890', 'zoom'],
            'zoom webinar' => ['https://zoom.us/w/81234567890', 'zoom'],
            'zoom registration' => ['https://zoom.us/meeting/register/tJEqde-gqTMvHdw', 'zoom'],
            'zoom legacy join' => ['https://zoom.us/join?confno=81234567890', 'zoom'],
            'zoom gov' => ['https://zoomgov.com/j/1611234567', 'zoom'],
            'zoom deep link' => ['zoommtg://zoom.us/join?confno=81234567890&pwd=x', 'zoom'],
            'zoom upper case host' => ['HTTPS://US06WEB.ZOOM.US/j/81234567890', 'zoom'],
            'zoom trailing punctuation' => ['https://zoom.us/j/81234567890.', 'zoom'],

            // Zoom: not class links.
            'zoom homepage' => ['https://zoom.us', null],
            'zoom help centre' => ['https://support.zoom.us/hc/en-us/articles/201362193', null],
            'zoom recording' => ['https://kca.zoom.us/rec/share/abc123', null],
            'zoom download' => ['https://zoom.us/download', null],
            'zoom id too short' => ['https://zoom.us/j/12345', null],
            'lookalike domain' => ['https://notzoom.us/j/81234567890', null],
            'zoom as subdomain of other' => ['https://zoom.us.evil.example/j/81234567890', null],
            'zoom only in query' => ['https://example.com/page?ref=zoom.us/j/81234567890', null],

            // Google Meet.
            'meet code' => ['https://meet.google.com/abc-defg-hij', 'meet'],
            'meet code with params' => ['https://meet.google.com/abc-defg-hij?authuser=1&hs=122', 'meet'],
            'meet bare, no scheme' => ['meet.google.com/abc-defg-hij', 'meet'],
            'meet lookup nickname' => ['https://meet.google.com/lookup/biology101', 'meet'],
            'meet short link' => ['https://g.co/meet/biology101', 'meet'],
            'meet upper case code' => ['https://meet.google.com/ABC-DEFG-HIJ', 'meet'],
            'meet landing page' => ['https://meet.google.com/landing', null],
            'meet root' => ['https://meet.google.com/', null],
            'meet wrong code shape' => ['https://meet.google.com/abcdefghij', null],
            'google docs' => ['https://docs.google.com/document/d/abc', null],
            'meet lookalike' => ['https://meet.google.com.evil.example/abc-defg-hij', null],

            // Microsoft Teams.
            'teams meetup join' => ['https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc%40thread.v2/0', 'teams'],
            'teams meet short' => ['https://teams.microsoft.com/meet/1234567890123', 'teams'],
            'teams live' => ['https://teams.live.com/meet/9123456789012', 'teams'],
            'teams homepage' => ['https://teams.microsoft.com/', null],
            'teams download' => ['https://www.microsoft.com/en-us/microsoft-teams/download-app', null],

            // Redirect wrappers.
            'google redirect' => [
                'https://www.google.com/url?q=https%3A%2F%2Fmeet.google.com%2Fabc-defg-hij&sa=D', 'meet',
            ],
            'safelinks redirect' => [
                'https://eur01.safelinks.protection.outlook.com/?url=https%3A%2F%2Fzoom.us%2Fj%2F81234567890&data=x',
                'zoom',
            ],
            'google redirect to non-meeting' => ['https://www.google.com/url?q=https%3A%2F%2Fexample.com', null],
            'plain google search' => ['https://www.google.com/search?q=zoom.us/j/81234567890', null],

            // Garbage.
            'empty' => ['', null],
            'whitespace' => ['   ', null],
            'ftp scheme' => ['ftp://zoom.us/j/81234567890', null],
            'javascript' => ['javascript:alert(1)', null],
        ];
    }

    public function test_classify_handles_null(): void {
        $this->assertNull(meeting_link_detector::classify(null));
    }

    public function test_find_in_text_extracts_links_from_html(): void {
        $html = '<p>Join live: <a href="https://kca.zoom.us/j/81234567890?pwd=abc&amp;uname=x">class</a></p>'
            . '<p>Backup: meet.google.com/abc-defg-hij.</p>';
        $found = meeting_link_detector::find_in_text($html);

        $this->assertCount(2, $found);
        $this->assertSame('zoom', $found[0]['provider']);
        $this->assertSame('meet', $found[1]['provider']);
    }

    public function test_find_in_text_deduplicates_repeated_links(): void {
        $text = 'https://zoom.us/j/81234567890 and again https://zoom.us/j/81234567890';
        $this->assertCount(1, meeting_link_detector::find_in_text($text));
    }

    public function test_find_in_text_ignores_non_meeting_links(): void {
        $text = 'See https://support.zoom.us/hc/en-us for help and https://docs.google.com/d/abc and '
            . 'https://zoom.us/download. Email msg.co/x is not a link.';
        $this->assertSame([], meeting_link_detector::find_in_text($text));
    }

    public function test_find_in_text_handles_empty_input(): void {
        $this->assertSame([], meeting_link_detector::find_in_text(null));
        $this->assertSame([], meeting_link_detector::find_in_text(''));
    }

    public function test_find_in_text_finds_deep_link_and_unwraps_redirects(): void {
        $text = 'Open zoommtg://zoom.us/join?confno=81234567890 or '
            . '<a href="https://www.google.com/url?q=https%3A%2F%2Fmeet.google.com%2Fabc-defg-hij&amp;sa=D">Meet</a>';
        $providers = array_column(meeting_link_detector::find_in_text($text), 'provider');
        sort($providers);
        $this->assertSame(['meet', 'zoom'], $providers);
    }

    public function test_label(): void {
        $this->assertSame('Zoom', meeting_link_detector::label('zoom'));
        $this->assertSame('Google Meet', meeting_link_detector::label('meet'));
        $this->assertSame('Microsoft Teams', meeting_link_detector::label('teams'));
        $this->assertSame('other', meeting_link_detector::label('other'));
    }
}
