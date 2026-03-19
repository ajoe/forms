<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Forms\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version050004Date20250319180638 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('forms_v2_uploaded_files')) {
			return null;
		}

		$table = $schema->getTable('forms_v2_uploaded_files');
		$pk = $table->getPrimaryKey();

		if ($pk !== null) {
			// Primary key already exists — do not drop and recreate it.
			// Unconditionally calling dropPrimaryKey() can corrupt Doctrine's
			// internal schema state and block subsequent migrations
			// (see bug #2954: missing allow_edit_submissions column).
			return null;
		}

		// No primary key exists (e.g. from a previous failed migration attempt
		// that dropped the PK but did not recreate it) — recreate it.
		$table->setPrimaryKey(['id'], 'forms_upload_files_id');
		return $schema;
	}
}
