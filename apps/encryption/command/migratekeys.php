<?php
/**
 * Copyright (c) 2015 Thomas Müller <thomas.mueller@tmit.eu>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OCA\Encryption\Command;

use OC\DB\Connection;
use OC\Files\View;
use OC\User\Manager;
use OCA\Encryption\Migration;
use OCP\IConfig;
use OCP\IUserBackend;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateKeys extends Command {

	/** @var \OC\User\Manager */
	private $userManager;

	/** @var View */
	private $view;
	/** @var \OC\DB\Connection */
	private $connection;
	/** @var IConfig */
	private $config;

	/**
	 * @param Manager $userManager
	 * @param View $view
	 * @param Connection $connection
	 * @param IConfig $config
	 */
	public function __construct(Manager $userManager,
								View $view,
								Connection $connection,
								IConfig $config) {

		$this->userManager = $userManager;
		$this->view = $view;
		$this->connection = $connection;
		$this->config = $config;
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('encryption:migrate')
			->setDescription('initial migration to encryption 2.0')
			->addArgument(
				'user_id',
				InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
				'will migrate keys of the given user(s)'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {

		// perform system reorganization
		$migration = new Migration($this->config, $this->view, $this->connection);

		$users = $input->getArgument('user_id');
		if (!empty($users)) {
			foreach ($users as $user) {
				if ($this->userManager->userExists($user)) {
					$output->writeln("Migrating keys   <info>$user</info>");
					$migration->reorganizeFolderStructureForUser($user);
				} else {
					$output->writeln("<error>Unknown user $user</error>");
				}
			}
		} else {
			$output->writeln("Reorganize system folder structure");
			$migration->reorganizeSystemFolderStructure();
			$migration->updateDB();
			foreach($this->userManager->getBackends() as $backend) {
				$name = get_class($backend);

				if ($backend instanceof IUserBackend) {
					$name = $backend->getBackendName();
				}

				$output->writeln("Migrating keys for users on backend <info>$name</info>");

				$limit = 500;
				$offset = 0;
				do {
					$users = $backend->getUsers('', $limit, $offset);
					foreach ($users as $user) {
						$output->writeln("   <info>$user</info>");
						$migration->reorganizeFolderStructureForUser($user);
					}
					$offset += $limit;
				} while(count($users) >= $limit);
			}
		}

	}
}
