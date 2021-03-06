<?php
/**
 * This file is part of the Passwords App
 * created by Marius David Wieschollek
 * and licensed under the AGPL.
 */

namespace OCA\Passwords\Hooks;

use OCA\Passwords\Db\Password;
use OCA\Passwords\Db\PasswordRevision;
use OCA\Passwords\Db\TagRevision;
use OCA\Passwords\Services\HelperService;
use OCA\Passwords\Services\Object\PasswordRevisionService;
use OCA\Passwords\Services\Object\PasswordService;
use OCA\Passwords\Services\Object\PasswordTagRelationService;
use OCA\Passwords\Services\Object\ShareService;
use OCA\Passwords\Services\Object\TagRevisionService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Class PasswordHook
 *
 * @package OCA\Passwords\Hooks\Password
 */
class PasswordHook {

    /**
     * @var ShareService
     */
    protected $shareService;

    /**
     * @var PasswordService
     */
    protected $passwordService;

    /**
     * @var PasswordRevisionService
     */
    protected $revisionService;

    /**
     * @var PasswordTagRelationService
     */
    protected $relationService;

    /**
     * @var TagRevisionService
     */
    protected $tagRevisionService;

    /**
     * @var HelperService
     */
    protected $helperService;

    /**
     * PasswordHook constructor.
     *
     * @param ShareService               $shareService
     * @param HelperService              $helperService
     * @param TagRevisionService         $tagRevisionService
     * @param PasswordRevisionService    $revisionService
     * @param PasswordTagRelationService $relationService
     */
    public function __construct(
        ShareService $shareService,
        HelperService $helperService,
        TagRevisionService $tagRevisionService,
        PasswordRevisionService $revisionService,
        PasswordTagRelationService $relationService
    ) {
        $this->shareService       = $shareService;
        $this->helperService      = $helperService;
        $this->revisionService    = $revisionService;
        $this->relationService    = $relationService;
        $this->tagRevisionService = $tagRevisionService;
    }

    /**
     * @param Password         $password
     * @param PasswordRevision $newRevision
     *
     * @throws DoesNotExistException
     * @throws \Exception
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function preSetRevision(Password $password, PasswordRevision $newRevision): void {
        if($password->getRevision() === null) {
            $this->checkSecurityStatus($newRevision);
            $this->revisionService->save($newRevision);

            return;
        }

        if($password->isEditable() && ($password->getShareId() || $password->hasShares())) {
            $this->updateShares($password);
        }

        /** @var PasswordRevision $oldRevision */
        $oldRevision = $this->revisionService->findByUuid($password->getRevision());
        if($oldRevision->getHidden() != $newRevision->getHidden()) {
            $this->updateRelations($password, $newRevision);
        }

        if($newRevision->getStatus() === 0) {
            if($newRevision->getHash() === $oldRevision->getHash()) {
                $newRevision->setStatus($oldRevision->getStatus());
            } else {
                $this->checkSecurityStatus($newRevision);
            }
            $this->revisionService->save($newRevision);
        }
    }

    /**
     * @param Password $password
     *
     * @throws \Exception
     */
    public function preDelete(Password $password): void {
        $relations = $this->relationService->findByPassword($password->getUuid());

        foreach($relations as $relation) {
            $this->relationService->delete($relation);
        }

        if($password->hasShares()) {
            $shares = $this->shareService->findBySourcePassword($password->getUuid());
            foreach($shares as $share) {
                $this->shareService->delete($share);
            }
            $password->setHasShares(false);
        }
    }

    /**
     * @param Password $password
     *
     * @throws \Exception
     */
    public function postDelete(Password $password): void {
        /** @var PasswordRevision[] $revisions */
        $revisions = $this->revisionService->findByModel($password->getUuid());

        foreach($revisions as $revision) {
            $this->revisionService->delete($revision);
        }

        if($password->getShareId()) {
            $share = $this->shareService->findByTargetPassword($password->getUuid());
            if($share !== null) $this->shareService->delete($share);
        }
    }

    /**
     * @param Password $originalPassword
     * @param Password $clonedPassword
     *
     * @throws \Exception
     */
    public function postClone(Password $originalPassword, Password $clonedPassword): void {
        /** @var PasswordRevision[] $revisions */
        $revisions = $this->revisionService->findByModel($originalPassword->getUuid());

        $currentClonedRevision = null;
        foreach($revisions as $revision) {
            /** @var PasswordRevision $revisionClone */
            $revisionClone = $this->revisionService->clone($revision, ['model' => $clonedPassword->getUuid()]);
            $this->revisionService->save($revisionClone);
            if($revision->getUuid() == $originalPassword->getRevision()) {
                $clonedPassword->setRevision($revisionClone->getUuid());
                $currentClonedRevision = $revisionClone;
            }
        }

        $relations = $this->relationService->findByPassword($originalPassword->getUuid());
        foreach($relations as $relation) {
            $relationClone = $this->relationService->clone($relation, [
                'password'         => $currentClonedRevision->getModel(),
                'passwordRevision' => $currentClonedRevision->getUuid(),
                'hidden'           => $currentClonedRevision->isHidden() || $relation->isHidden()
            ]);
            $this->relationService->save($relationClone);
        }
    }

    /**
     * @param Password $password
     *
     * @throws \Exception
     */
    protected function updateShares(Password $password): void {
        if($password->getShareId()) {
            $share = $this->shareService->findByTargetPassword($password->getUuid());
            $share->setTargetUpdated(true);
            $this->shareService->save($share);
        }

        if($password->hasShares()) {
            $shares = $this->shareService->findBySourcePassword($password->getUuid());
            foreach($shares as $share) {
                $share->setSourceUpdated(true);
                $this->shareService->save($share);
            }
        }
    }

    /**
     * @param PasswordRevision $newRevision
     */
    protected function checkSecurityStatus(PasswordRevision $newRevision): void {
        $securityCheck = $this->helperService->getSecurityHelper();
        list($status, $statusCode) = $securityCheck->getRevisionSecurityLevel($newRevision);
        $newRevision->setStatus($status);
        $newRevision->setStatusCode($statusCode);
    }

    /**
     * @param Password         $password
     * @param PasswordRevision $newRevision
     *
     * @throws \Exception
     */
    protected function updateRelations(Password $password, PasswordRevision $newRevision): void {
        $relations = $this->relationService->findByPassword($password->getUuid());

        foreach($relations as $relation) {
            /** @var TagRevision $tagRevision */
            $tagRevision = $this->tagRevisionService->findByModel($relation->getTag());
            $relation->setHidden($newRevision->isHidden() || $tagRevision->isHidden());
            $this->relationService->save($relation);
        }
    }
}