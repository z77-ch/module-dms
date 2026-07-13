<?php

namespace Z77\Module\Dms\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;
use Z77\Shared\Tree\TreeNode;
use Z77\Shared\Tree\TreeNodeTrait;

/**
 * A folder in the document management tree (ADR-016 / ADR-020 / ADR-021). Collection-mode
 * entity (auto-increment `id`); a tree node via {@see TreeNode} + {@see TreeNodeTrait}
 * (`parentId`/`sortKey`). The tree is the single source of truth for scope (ADR-020):
 * exactly ONE drive root sits at the top (`parentId = null`, {@see DRIVE_KEY}, ADR-021)
 * and its direct children ARE the partitions — real, ownable, ACL-capable entities.
 * There is no `area` label and no non-entity root.
 *
 * `key` is the stable module address of a partition (ADR-020 rule 4): a module declares
 * its partition as a code constant and resolves it via `DocumentService::rootFolder($key)`
 * (get-or-create). Only the drive root and partitions carry a key; human-created
 * partitions may leave it `null`.
 * **Server-controlled and hardened (S2):** the key must never come from request input
 * (root-squatting), so there is no `#[Clean]` path and the setter enforces the slug
 * charset. `system = true` marks a module-declared folder: for a ROOT this locks
 * rename/move/delete (the root slug is the top segment of every public URL — S4).
 */
#[Entity('file', 'documents/folders.json', invalidatesCache: true)]
class Folder implements TreeNode
{
    use ArrayMappable;
    use TreeNodeTrait; // parentId + sortKey (server-controlled tree fields)

    /**
     * Reserved `key` of the single drive root (ADR-021): the one mandatory top-level
     * folder (`parentId = null`, system-owned) whose children are the partitions. Never
     * a valid module key ({@see \Z77\Module\Dms\Services\DocumentService::rootFolder}
     * rejects it) and never a `/media` path segment (rule 2).
     */
    public const DRIVE_KEY = 'drive';

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    private ?int $id = null;

    #[Clean('text')]
    private string $name = '';

    /**
     * Stable module address of a root folder (ADR-020). Null for human-created folders
     * and for every non-root. Unique among roots (enforced by `rootFolder()`).
     * Server-controlled — MUST be a code constant, never request input (S2).
     */
    private ?string $key = null;

    /** Module-owned, mutation-protected folder. Server-controlled. */
    private bool $system = false;

    // ── DMS rebuild (ADR-017 / R1): ownership + ACL + delivery ─────────────────

    /** Owning principal (user id) — implicit full access (ADR-017). Server-controlled. */
    private ?int $ownerId = null;

    /** Output gate for the subtree (live folder). Server-validated. */
    private bool $active = true;

    /** URL-safe path segment; basis of the materialization path (R4). */
    private string $slug = '';

    /** `sealed|protected|public`; null = inherit from the parent chain (ADR-017). */
    private ?string $deliveryMode = null;

    /**
     * Image-profile name for uploads into this subtree; null = inherit from the parent
     * chain (same inheritance pattern as {@see $deliveryMode}). The name references a
     * profile of the folder's PARTITION in the project's DMS `imageProfilesConfig`
     * (partition-namespaced, project override). Server-controlled — assignment goes
     * through the gated `FolderService::setProfile()`, which validates the name against
     * the registry; the setter only normalizes (an unknown-but-well-formed name here is
     * harmless: resolution falls back to `default`/none at save time).
     */
    private ?string $profile = null;

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getKey(): ?string { return $this->key; }
    public function isSystem(): bool { return $this->system; }
    public function getOwnerId(): ?int { return $this->ownerId; }
    public function isActive(): bool { return $this->active; }
    public function getSlug(): string { return $this->slug; }
    public function getDeliveryMode(): ?string { return $this->deliveryMode; }
    public function getProfile(): ?string { return $this->profile; }

    public function setName(string $name): void { $this->name = $name; }

    /**
     * @throws \InvalidArgumentException when the key is empty or not slug-safe (S2)
     */
    public function setKey(?string $key): void
    {
        if ($key !== null && !preg_match('/^[a-z0-9][a-z0-9-]*$/', $key)) {
            throw new \InvalidArgumentException(
                "Folder key '{$key}' is invalid — expected the slug charset [a-z0-9-], not empty."
            );
        }
        $this->key = $key;
    }

    public function setSystem(bool $system): void { $this->system = $system; }
    public function setOwnerId(?int $ownerId): void
    {
        $this->ownerId = ($ownerId !== null && $ownerId > 0) ? $ownerId : null;
    }
    public function setActive(bool $active): void { $this->active = $active; }
    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function setDeliveryMode(?string $deliveryMode): void
    {
        $this->deliveryMode = in_array($deliveryMode, ['sealed', 'protected', 'public'], true) ? $deliveryMode : null;
    }

    /** Normalizing setter (pattern {@see setDeliveryMode}): empty/malformed → null. */
    public function setProfile(?string $profile): void
    {
        $profile = $profile !== null ? trim($profile) : null;
        $this->profile = ($profile !== null && preg_match('/^[a-z0-9_-]+$/i', $profile)) ? $profile : null;
    }
}
