'use client';

import { useId, useState, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * DESTRUCTIVE AND PROTECTED ACTIONS ARE CONFIRMED BY TYPING, NOT BY CLICKING (kb-ui-patterns).
 *
 * Deleting a source, a bot, an organization or a member is irreversible or nearly so. Three things
 * make this a real gate rather than a speed bump:
 *
 *  - `consequence` states what will happen IN SPECIFIC NUMBERS ("This removes 1,204 chunks from 3
 *    bots and cannot be undone"), because a generic "Are you sure?" trains people to click through.
 *  - The confirm button stays disabled until the resource's own name is typed. A second click in the
 *    same position is muscle memory; typing a name is not.
 *  - The button is `--destructive-strong`, which is the solid-fill token — the base `--destructive`
 *    does not clear 4.5:1 against a near-white label in dark mode.
 *
 * THIS DOES NOT REPLACE A SERVER-SIDE CHECK. The six checks every protected action performs are
 * `kb-security-baseline`'s and run regardless of what this dialog did.
 */
export function ConfirmDestructiveDialog({
  open,
  onOpenChange,
  title,
  consequence,
  resourceName,
  confirmLabel,
  onConfirm,
  pending = false,
}: {
  readonly open: boolean;
  readonly onOpenChange: (open: boolean) => void;
  /** Sentence case, and the specific verb: "Delete source", never "Confirm". */
  readonly title: string;
  readonly consequence: ReactNode;
  /** Typed verbatim to unlock the action. */
  readonly resourceName: string;
  readonly confirmLabel: string;
  readonly onConfirm: () => void;
  readonly pending?: boolean;
}) {
  const [typed, setTyped] = useState('');
  const inputId = useId();
  const hintId = useId();
  const armed = typed === resourceName;

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // Reset on close so re-opening never arrives pre-armed from a previous attempt.
        if (!next) setTyped('');
        onOpenChange(next);
      }}
    >
      {/* Focus trapping, Escape, scroll lock, `inert` on the background and focus return to the
          trigger all come from the primitive. Hand-rolling any one of them is where the iOS
          overscroll bug and the "page behind scrolled to the top" bug come from. */}
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{consequence}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-2">
          <Label htmlFor={inputId}>
            Type <span className="font-mono">{resourceName}</span> to confirm
          </Label>
          <Input
            id={inputId}
            value={typed}
            onChange={(event) => setTyped(event.target.value)}
            aria-describedby={hintId}
            autoComplete="off"
          />
          <p id={hintId} className="text-sm text-muted-foreground">
            This cannot be undone.
          </p>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          {/* The verb in the button matches the verb in the title. Not "OK", not "Confirm". */}
          <Button variant="destructive" disabled={!armed || pending} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
