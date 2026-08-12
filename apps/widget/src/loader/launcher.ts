/**
 * The launcher button and the panel's open/closed state — the ~2 kB of chrome that CANNOT live in
 * the iframe.
 *
 * Why it is not in the frame: the launcher and the sliding panel must animate and overflow the
 * host viewport, and an iframe cannot paint outside its own box. Doing this inside the frame means
 * a full-screen transparent iframe pinned over the customer's page, which swallows their clicks.
 * So the chrome lives in a CLOSED shadow root, and the chat lives in the iframe. Neither isolation
 * mechanism is sufficient alone; each is used at its strength.
 *
 * Plain TypeScript and DOM. There is no Preact in this file, in this directory, or in the loader
 * bundle at all.
 */

export interface LauncherHandle {
  readonly button: HTMLButtonElement;
  /** Reflects the panel state into the DOM. Called by the button AND by an `open`/`close`/`toggle`
   *  request that came from the frame, so the two can never disagree. */
  setOpen(open: boolean): void;
  isOpen(): boolean;
}

/**
 * Signature note: `preact-vite-library` sketches this as `buildLauncher(root, frame)`. The third
 * argument is a superset, not a redesign — the loader has to hear about a click so it can post
 * `open`/`close` over the bridge; without it the button would toggle a frame that never learns the
 * panel opened, and `widget.opened` would never fire.
 */
export function buildLauncher(
  root: ShadowRoot,
  frame: HTMLIFrameElement,
  onToggle: (open: boolean) => void,
): LauncherHandle {
  const position = frame.dataset['position'] === 'left' ? 'left' : 'right';

  const button = document.createElement('button');
  // A real <button>, typed explicitly: inside a <form> on the host page an untyped button submits.
  button.type = 'button';
  button.className = 'kb-launcher';
  button.dataset['position'] = position;
  button.setAttribute('aria-haspopup', 'dialog');
  button.setAttribute('aria-expanded', 'false');
  // Never innerHTML, not even for our own literal markup: this file is one careless refactor away
  // from interpolating a customer-supplied label into it.
  const glyph = document.createElement('span');
  glyph.className = 'kb-launcher-glyph';
  glyph.setAttribute('aria-hidden', 'true');
  glyph.textContent = '\u{1F4AC}';
  const label = document.createElement('span');
  label.className = 'kb-launcher-label';
  label.textContent = 'Open support chat';
  button.append(glyph, label);

  let open = false;

  const setOpen = (next: boolean): void => {
    open = next;
    // dataset, never setAttribute('style') and never a CSS string built from input.
    frame.dataset['open'] = next ? 'true' : 'false';
    button.setAttribute('aria-expanded', next ? 'true' : 'false');
    label.textContent = next ? 'Close support chat' : 'Open support chat';
  };

  setOpen(false);

  button.addEventListener('click', () => {
    setOpen(!open);
    onToggle(open);
  });

  // `root` is the closed shadow root. It is passed in rather than reached for, so this function
  // has no way to touch the host document.
  void root;

  return { button, setOpen, isOpen: () => open };
}
