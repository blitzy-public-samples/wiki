/**
 * Ambient type declarations for the checkbox hack utility that MediaWiki core exposes as
 * `require( 'mediawiki.page.ready' ).checkboxHack`.
 *
 * The checkbox hack composes three elements: a visually hidden `input[type="checkbox"]`, a `label`
 * that acts as the visible button, and a target element. Showing and hiding the target is done
 * entirely in CSS through the checkbox's `:checked` state, which is why the disclosure widgets
 * built on it (the main menu, the user links menu, the language and variants menus, the page tools
 * menu and the collapsible table of contents) remain fully usable when JavaScript is unavailable.
 *
 * The functions declared below are progressive enhancements layered on top of that CSS-only
 * behaviour. They keep `aria-expanded` in sync so assistive technology reports the widget's state,
 * they avoid the distracting focus ring when a pointing device is used, and they dismiss the target
 * when the user clicks outside it or moves focus away.
 *
 * Both interfaces are deliberately global rather than module exports: consuming scripts describe
 * the value read from the `mediawiki.page.ready` module with an inline JSDoc type annotation and
 * never import this file. Nothing declared here has a runtime counterpart in this skin. Core owns
 * the implementation; this file only records the contract so that the plain JavaScript in
 * `resources/skins.notion.js/` can be type-checked against it.
 *
 * Parameter types are declared in the signatures below and deliberately not repeated in the
 * documentation tags, so the two can never drift apart.
 */

/**
 * The event listeners a `bind*` call registered, keyed by the behaviour that added them.
 *
 * Every member is optional because each `bind*` function reports only the listeners it is itself
 * responsible for. `bind` returns the union of all of them, and handing that union back to `unbind`
 * removes exactly the listeners that were added, leaving the CSS-only toggle behaviour intact.
 */
interface CheckboxHackListeners {
	onUpdateAriaExpandedOnInput?: EventListenerOrEventListenerObject;
	onToggleOnClick?: EventListenerOrEventListenerObject;
	onDismissOnClickOutside?: EventListenerOrEventListenerObject;
	onDismissOnFocusLoss?: EventListenerOrEventListenerObject;
}

/**
 * The checkbox hack utility, obtained with `require( 'mediawiki.page.ready' ).checkboxHack`.
 *
 * `bind` composes the individual enhancements and is the only member most callers need; the
 * constituents are declared separately for the cases that require finer control. Each `bind*`
 * member returns the listeners it registered so that they can later be passed to `unbind`.
 */
interface CheckboxHack {
	/**
	 * Revise the button's `aria-expanded` state so that it matches the checked state of the
	 * checkbox. Keeping the two in step is what makes the widget legible to assistive technology.
	 *
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 */
	updateAriaExpanded(
		checkbox: HTMLInputElement, button: HTMLElement
	): void;

	/**
	 * Update the `aria-expanded` attribute whenever the checkbox state, and therefore target
	 * visibility, changes.
	 *
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 */
	bindUpdateAriaExpandedOnInput(
		checkbox: HTMLInputElement, button: HTMLElement
	): CheckboxHackListeners;

	/**
	 * Toggle the checkbox manually when the button is clicked, which changes target visibility
	 * without moving focus to the control as a pointing device otherwise would.
	 *
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 */
	bindToggleOnClick(
		checkbox: HTMLInputElement, button: HTMLElement
	): CheckboxHackListeners;

	/**
	 * Toggle the checkbox manually when the button has focus and SPACE or ENTER is pressed, so that
	 * the label behaves like a button for keyboard users.
	 *
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 */
	bindToggleOnSpaceEnter(
		checkbox: HTMLInputElement, button: HTMLElement
	): CheckboxHackListeners;

	/**
	 * Dismiss the target when a click lands outside both the button and the target, and update
	 * `aria-expanded` to match the resulting checkbox state.
	 *
	 * @param window The window whose click events are observed.
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 * @param target The node whose visibility follows the checkbox state.
	 */
	bindDismissOnClickOutside(
		window: Window, checkbox: HTMLInputElement, button: HTMLElement, target: Node
	): CheckboxHackListeners;

	/**
	 * Dismiss the target when focus moves outside both the button and the target, and update
	 * `aria-expanded` to match the resulting checkbox state.
	 *
	 * @param window The window whose focus events are observed.
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 * @param target The node whose visibility follows the checkbox state.
	 */
	bindDismissOnFocusLoss(
		window: Window, checkbox: HTMLInputElement, button: HTMLElement, target: Node
	): CheckboxHackListeners;

	/**
	 * Apply every enhancement at once: keep `aria-expanded` in sync, toggle without a focus change
	 * on pointer and keyboard interaction, and dismiss the target on an outside click or on focus
	 * loss. This is the only interaction most call sites need.
	 *
	 * @param window The window whose click and focus events are observed.
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 * @param target The node whose visibility follows the checkbox state.
	 */
	bind(
		window: Window, checkbox: HTMLInputElement, button: HTMLElement, target: Node
	): CheckboxHackListeners;

	/**
	 * Remove listeners previously registered by `bind` or by an individual `bind*` member. The
	 * widget keeps working afterwards because the open and closed states are driven by CSS.
	 *
	 * @param window The window the listeners were attached to.
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 * @param listeners The listeners reported by the matching bind call.
	 */
	unbind(
		window: Window, checkbox: HTMLInputElement, button: HTMLElement,
		listeners: CheckboxHackListeners
	): void;
}
