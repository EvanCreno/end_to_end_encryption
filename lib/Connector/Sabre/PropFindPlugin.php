<?php
/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\EndToEndEncryption\Connector\Sabre;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\EndToEndEncryption\UserAgentManager;
use OCP\IRequest;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

class PropFindPlugin extends ServerPlugin {

	/* @var Server */
	private $server;

	/** @var UserAgentManager */
	private $userAgentManager;

	/** @var IRequest */
	private $request;

	/**
	 * PropFindPlugin constructor.
	 *
	 * @param UserAgentManager $userAgentManager
	 * @param IRequest $request
	 */
	public function __construct(UserAgentManager $userAgentManager, IRequest $request) {
		$this->userAgentManager = $userAgentManager;
		$this->request = $request;
	}

	/**
	 * {@inheritdoc}
	 */
	public function initialize(Server $server) {
		$this->server = $server;
		$this->server->on('propFind', [$this, 'updateProperty']);
	}

	/**
	 * remove permissions of end-to-end encrypted files for unsupported clients
	 *
	 * @param PropFind $propFind
	 * @param INode $node
	 */
	public function updateProperty(PropFind $propFind, INode $node) {
		$userAgent = $this->request->getHeader('USER_AGENT');
		$supportE2EEncryption = $this->userAgentManager->supportsEndToEndEncryption($userAgent);
		if (is_a($node, Directory::class) && !$supportE2EEncryption) {
			// encrypted files have only read permissions
			$isEncrypted = $propFind->get('{http://nextcloud.org/ns}is-encrypted');
			if ($isEncrypted === '1') {
				$propFind->set('{http://owncloud.org/ns}permissions', '', 200);
			}
		}
	}

}
