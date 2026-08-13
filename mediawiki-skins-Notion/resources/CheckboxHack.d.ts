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
 * they let the label behave like a button for pointer and keyboard users, and they dismiss the
 * target when the user clicks outside it, moves focus away, or follows a link inside it.
 *
 * Both the interface and the cleanup-function type are deliberately global rather than module
 * exports: consuming scripts describe the value read from the `mediawiki.page.ready` module with an
 * inline JSDoc type annotation and never import this file. Nothing declared here has a runtime
 * counterpart in this skin. Core owns the implementation; this file only records the contract so
 * that the plain JavaScript in `resources/skins.notion.js/` can be type-checked against it.
 *
 * The declaration mirrors core's module member for member, and that fidelity is the whole point of
 * the file: a signature that describes an older API type-checks a call core will reject at runtime.
 * It is therefore written against
 * `mediawiki/resources/src/mediawiki.page.ready/checkboxHack.js` as shipped by the MediaWiki
 * version this skin requires (>= 1.47), not against an inherited copy. Two consequences of that
 * are worth stating explicitly, because both differ from older declarations of this API:
 *
 *   - every `bind*` member returns a single cleanup function rather than a record of the
 *     listeners it registered, and
 *   - core exports no `unbind` member at all. Listeners are removed by calling the cleanup
 *     function the corresponding `bind*` call returned.
 *
 * Every `bind*` member returns a cleanup function that removes exactly the listeners that call
 * registered and nothing else; the widget keeps working after cleanup because the open and closed
 * states are driven by CSS, not by these listeners. The members and their order below mirror core's
 * own `module.exports`, and this file must be kept in step with it.
 *
 * Parameter types are declared in the signatures below and deliberately not repeated in the
 * documentation tags, so the two can never drift apart.
 */

/**
 * What every `bind*` member returns: a function that removes the event listeners that call
 * registered, and nothing else.
 *
 * Calling it leaves the widget working, because the open and closed states are driven by CSS
 * rather than by the listeners. `bind()` returns one of these that runs the cleanups of all six
 * enhancements it composed.
 */
type CheckboxHackCleanup = () => void;

/**
 * The checkbox hack utility, obtained with `require( 'mediawiki.page.ready' ).checkboxHack`.
 *
 * `bind` composes the individual enhancements and is the only member most callers need; the
 * constituents are declared separately for the cases that require finer control.
 */
interface CheckboxHack {
	/**
	 * Revise the checkbox's `aria-expanded` state so that it matches its checked state. Keeping
	 * the two in step is what makes the widget legible to assistive technology.
	 *
	 * The attribute is set on the checkbox. Passing `button` moves it onto the button instead and
	 * is deprecated since MediaWiki 1.38: core emits an `mw.log.warn` naming this function when it
	 * is supplied, so new call sites omit it.
	 *
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button Deprecated since MediaWiki 1.38. The visible label associated with the
	 *   checkbox; omit it so the state stays on the checkbox.
	 */
	updateAriaExpanded(
		checkbox: HTMLInputElement, button?: HTMLElement
	): void;

	/**
	 * Update `aria-expanded` whenever the checkbox state, and therefore target visibility, changes.
	 *
	 * Passing `button` carries the same 1.38 deprecation as `updateAriaExpanded`.
	 *
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button Deprecated since MediaWiki 1.38, exactly as in `updateAriaExpanded`: the
	 *   visible label associated with the checkbox.
	 */
	bindUpdateAriaExpandedOnInput(
		checkbox: HTMLInputElement, button?: HTMLElement
	): CheckboxHackCleanup;

	/**
	 * Toggle the checkbox manually when the button is clicked, which changes target visibility
	 * without moving focus to the control as a pointing device otherwise would.
	 *
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 * @return Cleanup function that removes the added event listeners.
	 */
	bindToggleOnClick(
		checkbox: HTMLInputElement, button: HTMLElement
	): CheckboxHackCleanup;

	/**
	 * Toggle the checkbox manually when the button has focus and SPACE or ENTER is pressed.
	 *
	 * @deprecated since MediaWiki 1.38. Use `bindToggleOnEnter` instead, which needs no button and
	 *   does not intercept SPACE; core warns on every call to this function.
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 * @return Cleanup function that removes the added event listeners.
	 */
	bindToggleOnSpaceEnter(
		checkbox: HTMLInputElement, button: HTMLElement
	): CheckboxHackCleanup;

	/**
	 * Toggle the checkbox manually when it has focus and ENTER is pressed, so that the widget
	 * behaves like a button for keyboard users. The listener is bound to the checkbox itself,
	 * which is the element that carries `role="button"`.
	 *
	 * @param checkbox The visually hidden checkbox that controls the target.
	 */
	bindToggleOnEnter(
		checkbox: HTMLInputElement
	): CheckboxHackCleanup;

	/**
	 * Dismiss the target when a click lands outside the checkbox, the button and the target, and
	 * update `aria-expanded` to match the resulting checkbox state.
	 *
	 * @param window The window whose click events are observed.
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 * @param target The node whose visibility follows the checkbox state.
	 * @return Cleanup function that removes the added event listeners.
	 */
	bindDismissOnClickOutside(
		window: Window, checkbox: HTMLInputElement, button: HTMLElement, target: Node
	): CheckboxHackCleanup;

	/**
	 * Dismiss the target when focus moves outside the checkbox, the button and the target, and
	 * update `aria-expanded` to match the resulting checkbox state.
	 *
	 * @param window The window whose focus events are observed.
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 * @param target The node whose visibility follows the checkbox state.
	 * @return Cleanup function that removes the added event listeners.
	 */
	bindDismissOnFocusLoss(
		window: Window, checkbox: HTMLInputElement, button: HTMLElement, target: Node
	): CheckboxHackCleanup;

	/**
	 * Dismiss the target when a link inside it is followed, so the widget is not left open
	 * behind the newly navigated page.
	 *
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param target The node whose visibility follows the checkbox state.
	 */
	bindDismissOnClickLink(
		checkbox: HTMLInputElement, target: Node
	): CheckboxHackCleanup;

	/**
	 * Apply every enhancement at once: keep `aria-expanded` in sync, toggle on click and on
	 * ENTER, and dismiss the target on an outside click, on focus loss and on following a link
	 * inside it. This is the only interaction most call sites need.
	 *
	 * It composes `bindUpdateAriaExpandedOnInput` without a button -- so the attribute lands on the
	 * checkbox, per the 1.38 change -- together with `bindToggleOnClick`, `bindToggleOnEnter`,
	 * `bindDismissOnClickOutside`, `bindDismissOnFocusLoss` and `bindDismissOnClickLink`. The
	 * deprecated `bindToggleOnSpaceEnter` is deliberately not part of it, and the single cleanup
	 * returned calls each constituent cleanup in turn.
	 *
	 * @param window The window whose click and focus events are observed.
	 * @param checkbox The visually hidden checkbox that controls the target.
	 * @param button The visible label associated with the checkbox.
	 * @param target The node whose visibility follows the checkbox state.
	 * @return Cleanup function that removes every added event listener.
	 */
	bind(
		window: Window, checkbox: HTMLInputElement, button: HTMLElement, target: Node
	): CheckboxHackCleanup;
}
