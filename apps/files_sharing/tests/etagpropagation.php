<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_sharing\Tests;

use OC\Files\Filesystem;
use OC\Files\View;

class EtagPropagation extends TestCase {
	/**
	 * @var \OC\Files\View
	 */
	private $rootView;
	protected $rootIds = [];
	protected $rootEtags = [];

	protected function setUp() {
		parent::setUp();
		$this->setUpShares();
	}

	/**
	 * "user1" is the admin who shares a folder "sub1/sub2/folder" with "user2" and "user3"
	 * "user2" receives the folder and puts it in "sub1/sub2/folder"
	 * "user3" receives the folder and puts it in "sub1/sub2/folder"
	 * "user2" reshares the subdir "sub1/sub2/folder/inside" with "user4"
	 * "user4" puts the received "inside" folder into "sub1/sub2/inside" (this is to check if it propagates across multiple subfolders)
	 */
	private function setUpShares() {
		$this->rootView = new View('');
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER1);
		$view1 = new View('/' . self::TEST_FILES_SHARING_API_USER1 . '/files');
		$view1->mkdir('/sub1/sub2/folder/inside');
		$view1->mkdir('/directReshare');
		$view1->mkdir('/sub1/sub2/folder/other');
		$view1->mkdir('/sub1/sub2/folder/other');
		$view1->file_put_contents('/sub1/sub2/folder/file.txt', 'foobar');
		$view1->file_put_contents('/sub1/sub2/folder/inside/file.txt', 'foobar');
		$folderInfo = $view1->getFileInfo('/sub1/sub2/folder');
		\OCP\Share::shareItem('folder', $folderInfo->getId(), \OCP\Share::SHARE_TYPE_USER, self::TEST_FILES_SHARING_API_USER2, 31);
		\OCP\Share::shareItem('folder', $folderInfo->getId(), \OCP\Share::SHARE_TYPE_USER, self::TEST_FILES_SHARING_API_USER3, 31);
		$folderInfo = $view1->getFileInfo('/directReshare');
		\OCP\Share::shareItem('folder', $folderInfo->getId(), \OCP\Share::SHARE_TYPE_USER, self::TEST_FILES_SHARING_API_USER2, 31);
		$this->rootIds[self::TEST_FILES_SHARING_API_USER1] = $view1->getFileInfo('')->getId();

		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER2);
		$view2 = new View('/' . self::TEST_FILES_SHARING_API_USER2 . '/files');
		$view2->mkdir('/sub1/sub2');
		$view2->rename('/folder', '/sub1/sub2/folder');
		$insideInfo = $view2->getFileInfo('/sub1/sub2/folder/inside');
		\OCP\Share::shareItem('folder', $insideInfo->getId(), \OCP\Share::SHARE_TYPE_USER, self::TEST_FILES_SHARING_API_USER4, 31);
		$folderInfo = $view2->getFileInfo('/directReshare');
		\OCP\Share::shareItem('folder', $folderInfo->getId(), \OCP\Share::SHARE_TYPE_USER, self::TEST_FILES_SHARING_API_USER4, 31);
		$this->rootIds[self::TEST_FILES_SHARING_API_USER2] = $view2->getFileInfo('')->getId();

		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER3);
		$view3 = new View('/' . self::TEST_FILES_SHARING_API_USER3 . '/files');
		$view3->mkdir('/sub1/sub2');
		$view3->rename('/folder', '/sub1/sub2/folder');
		$this->rootIds[self::TEST_FILES_SHARING_API_USER3] = $view3->getFileInfo('')->getId();

		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER4);
		$view4 = new View('/' . self::TEST_FILES_SHARING_API_USER4 . '/files');
		$view4->mkdir('/sub1/sub2');
		$view4->rename('/inside', '/sub1/sub2/inside');
		$this->rootIds[self::TEST_FILES_SHARING_API_USER4] = $view4->getFileInfo('')->getId();

		foreach ($this->rootIds as $user => $id) {
			$path = $this->rootView->getPath($id);
			$this->rootEtags[$user] = $this->rootView->getFileInfo($path)->getEtag();
		}
	}

	/**
	 * @param string[] $users
	 */
	private function assertRootEtagsChanged($users) {
		$oldUser = \OC::$server->getUserSession()->getUser();
		foreach ($users as $user) {
			$this->loginAsUser($user);
			$path = $this->rootView->getPath($this->rootIds[$user]);
			$etag = $this->rootView->getFileInfo($path)->getEtag();
			$this->assertNotEquals($this->rootEtags[$user], $etag, 'Failed asserting that the root etag for ' . $user . ' has changed');
			$this->rootEtags[$user] = $etag;
		}
		$this->loginAsUser($oldUser->getUID());
	}

	public function testOwnerWritesToShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER1);
		Filesystem::file_put_contents('/sub1/sub2/folder/asd.txt', 'bar');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3]);
	}

	public function testOwnerWritesToShareWithReshare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER1);
		Filesystem::file_put_contents('/sub1/sub2/folder/inside/bar.txt', 'bar');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testOwnerRenameInShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER1);
		Filesystem::rename('/sub1/sub2/folder/file.txt', '/sub1/sub2/folder/renamed.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3]);
	}

	public function testOwnerRenameInReShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER1);
		Filesystem::rename('/sub1/sub2/folder/inside/file.txt', '/sub1/sub2/folder/inside/renamed.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testOwnerRenameIntoReShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER1);
		Filesystem::rename('/sub1/sub2/folder/file.txt', '/sub1/sub2/folder/inside/renamed.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testOwnerRenameOutOfReShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER1);
		Filesystem::rename('/sub1/sub2/folder/inside/file.txt', '/sub1/sub2/folder/renamed.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testOwnerDeleteInShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER1);
		Filesystem::unlink('/sub1/sub2/folder/file.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3]);
	}

	public function testOwnerDeleteInReShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER1);
		Filesystem::unlink('/sub1/sub2/folder/inside/file.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testRecipientWritesToShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER2);
		Filesystem::file_put_contents('/sub1/sub2/folder/asd.txt', 'bar');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3]);
	}

	public function testRecipientWritesToReshare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER2);
		Filesystem::file_put_contents('/sub1/sub2/folder/inside/asd.txt', 'bar');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testRecipientWritesToOtherRecipientsReshare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER3);
		Filesystem::file_put_contents('/sub1/sub2/folder/inside/asd.txt', 'bar');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testRecipientRenameInShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER2);
		Filesystem::rename('/sub1/sub2/folder/file.txt', '/sub1/sub2/folder/renamed.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3]);
	}

	public function testRecipientRenameInReShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER2);
		Filesystem::rename('/sub1/sub2/folder/inside/file.txt', '/sub1/sub2/folder/inside/renamed.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testRecipientRenameResharedFolder() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER2);
		Filesystem::rename('/directReshare', '/sub1/directReshare');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER2]);
	}

	public function testRecipientDeleteInShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER2);
		Filesystem::unlink('/sub1/sub2/folder/file.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3]);
	}

	public function testRecipientDeleteInReShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER2);
		Filesystem::unlink('/sub1/sub2/folder/inside/file.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testReshareRecipientWritesToReshare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER4);
		Filesystem::file_put_contents('/sub1/sub2/inside/asd.txt', 'bar');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testReshareRecipientRenameInReShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER4);
		Filesystem::rename('/sub1/sub2/inside/file.txt', '/sub1/sub2/inside/renamed.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}

	public function testReshareRecipientDeleteInReShare() {
		$this->loginAsUser(self::TEST_FILES_SHARING_API_USER4);
		Filesystem::unlink('/sub1/sub2/inside/file.txt');
		$this->assertRootEtagsChanged([self::TEST_FILES_SHARING_API_USER1, self::TEST_FILES_SHARING_API_USER2,
			self::TEST_FILES_SHARING_API_USER3, self::TEST_FILES_SHARING_API_USER4]);
	}
}
