<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Forms\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Repair migration for bug #2954: ensure columns that may have been
 * silently skipped by earlier migrations are present.
 *
 * This migration sorts alphabetically AFTER Version050200* and acts
 * as a safety net for installations where the previous migrations
 * were recorded as executed but the schema changes did not persist.
 */
class Version050201Date20250319000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('forms_v2_forms');
		$changed = false;

		if (!$table->hasColumn('allow_edit_submissions')) {
			$table->addColumn('allow_edit_submissions', Types::BOOLEAN, [
				'notnull' => false,
				'default' => 0,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('locked_by')) {
			$table->addColumn('locked_by', Types::STRING, [
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('locked_until')) {
			$table->addColumn('locked_until', Types::INTEGER, [
				'notnull' => false,
				'default' => null,
				'comment' => 'unix-timestamp',
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
