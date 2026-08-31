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

/**
 * Unit tests for the local_pagegrader privacy provider.
 *
 * @package    local_pagegrader
 * @copyright  2026 SgtLomzik <lomzike@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_pagegrader\privacy;

/**
 * Tests that the plugin correctly declares that it stores no personal data.
 *
 * The grades the plugin awards belong to the core gradebook, which exports and
 * deletes them through its own provider.
 *
 * @package    local_pagegrader
 * @copyright  2026 SgtLomzik <lomzike@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pagegrader\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The provider is registered with the privacy subsystem as a null provider.
     */
    public function test_the_provider_is_a_null_provider(): void {
        $this->assertTrue(
            is_subclass_of(provider::class, \core_privacy\local\metadata\null_provider::class)
        );
    }

    /**
     * The reason names a language string that actually exists.
     */
    public function test_get_reason_names_an_existing_string(): void {
        $this->resetAfterTest();

        $reason = provider::get_reason();

        $this->assertSame('privacy:metadata', $reason);
        $this->assertTrue(get_string_manager()->string_exists($reason, 'local_pagegrader'));
        $this->assertNotEmpty(get_string($reason, 'local_pagegrader'));
    }

    /**
     * The privacy manager reports the plugin as storing nothing.
     */
    public function test_the_manager_reports_no_stored_data(): void {
        $this->resetAfterTest();

        $this->assertTrue((new \core_privacy\manager())->component_is_compliant('local_pagegrader'));
    }

    /**
     * The plugin's own table holds configuration only, never a user id.
     */
    public function test_the_plugin_table_holds_no_user_column(): void {
        global $DB;

        $this->resetAfterTest();

        $columns = $DB->get_columns('local_pagegrader');

        $this->assertArrayNotHasKey('userid', $columns);
        $this->assertEqualsCanonicalizing(
            ['id', 'coursemoduleid', 'enablegrading', 'maxgrade'],
            array_keys($columns)
        );
    }
}
