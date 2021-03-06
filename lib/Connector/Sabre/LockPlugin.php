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

use OC\AppFramework\Http;
use OCA\EndToEndEncryption\LockManager;
use OCA\DAV\Connector\Sabre\Exception\FileLocked;
use OCA\DAV\Connector\Sabre\Exception\Forbidden;
use OCA\EndToEndEncryption\UserAgentManager;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUserSession;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;

class LockPlugin extends ServerPlugin {

	/* @var Server */
	private $server;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IUserSession */
	private $userSession;

	/** @var LockManager */
	private $lockManager;

	/** @var UserAgentManager */
	private $userAgentManager;

	/**
	 * LockPlugin constructor.
	 *
	 * @param IRootFolder $rootFolder
	 * @param IUserSession $userSession
	 * @param LockManager $lockManager
	 * @param UserAgentManager $userAgentManager
	 */
	public function __construct(IRootFolder $rootFolder,
								IUserSession $userSession,
								LockManager $lockManager,
								UserAgentManager $userAgentManager
	) {
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->lockManager = $lockManager;
		$this->userAgentManager = $userAgentManager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function initialize(Server $server) {
		$this->server = $server;
		$this->server->on('beforeMethod', [$this, 'checkLock'], 200);
	}

	/**
	 * Check if a file is locked for end-to-end encryption before trying to download it
	 *
	 * @param RequestInterface $request
	 * @throws FileLocked
	 */
	public function checkLock(RequestInterface $request) {

		$userAgent = $request->getHeader('user-agent');

		$this->checkUserAgent($userAgent, $request->getPath());
		if ($request->getMethod() === 'GET') {
			$node = $this->server->tree->getNodeForPath($request->getPath());
			if ($this->lockManager->isLocked($node->getId(), '')) {
				throw new FileLocked('file is locked', Http::STATUS_FORBIDDEN);
			}
		}
	}

	/**
	 * Check if user agent is allowed to access a end-to-end encrypted folder
	 *
	 * @param string $userAgent
	 * @param string $path
	 * @throws Forbidden
	 * @throws NotFound
	 */
	protected function checkUserAgent($userAgent, $path) {
		if (!$this->userAgentManager->supportsEndToEndEncryption($userAgent)) {
			try {
				$node = $this->getNode($path);
			} catch (NotFound $e) {
				// maybe we create a new file, try the parent folder
				$node = $this->getNode(dirname($path));
			}
			while ($node->isEncrypted() === false) {
				$node = $node->getParent();
				if ($node->getPath() === '/') {
					// top-level folder reached
					return;
				}
			}

			throw new Forbidden('client not allowed to access end-to-end encrypted content');
		}
	}

	/**
	 * get file system node of requested file
	 *
	 * @param string $path
	 * @return Node
	 *
	 * @throws NotFound
	 */
	protected function getNode($path) {
		try {
			$uid = $this->userSession->getUser()->getUID();
			$userRoot = $this->rootFolder->getUserFolder($uid);
			$node = $userRoot->get($path);
			return $node;
		} catch (\Exception $e) {
			throw new NotFound('file not found', Http::STATUS_NOT_FOUND, $e);
		}
	}

}
