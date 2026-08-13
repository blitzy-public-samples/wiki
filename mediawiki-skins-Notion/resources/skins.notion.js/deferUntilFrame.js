/**
 * Helper method that waits a given number of `requestAnimationFrame` callbacks
 * and then invokes a callback.
 *
 * `requestAnimationFrame` callbacks run during the frame's rendering steps, just
 * before that frame is painted. So with `frameCount` of N the callback runs
 * inside the Nth animation-frame callback, immediately before the Nth paint --
 * not after N paints have completed, and not before all of them: the N-1 paints
 * in between do happen. `frameCount` of 0 invokes the callback synchronously,
 * with no frame waited at all.
 *
 * The frame count is validated rather than trusted. A negative, fractional or non-finite count
 * would never reach the terminating comparison if that comparison were an equality test, leaving
 * an unbounded `requestAnimationFrame` chain running for the lifetime of the page; rejecting the
 * value outright surfaces the mistake at the call site instead. `<= 0` is used for the same reason:
 * it terminates on any count the guard let through, not only on exactly zero.
 *
 * Scheduled work is cancellable. A consumer that removes the element it was going to measure -- a
 * pinnable region moved between containers, a sticky header dismantled -- calls the returned
 * function and the pending frame is dropped, so the callback never runs against a detached node.
 * The returned function is safe to call more than once and after the callback has already run;
 * both are no-ops. A count of `0` invokes the callback synchronously, before this function
 * returns, so there is nothing left to cancel in that case.
 *
 * @param {Function} callback
 * @param {number} frameCount The number of animation frames to wait before
 * calling the specified callback. Must be a non-negative integer: a negative,
 * fractional or non-finite count is rejected by the guard below rather than
 * scheduling frames forever, and the recursion terminates on `<= 0` so that any
 * count the guard let through does stop.
 * @return {function(): void} Cancels the pending callback if it has not run yet.
 * @throws {Error} If frameCount is not a non-negative integer.
 */
function deferUntilFrame( callback, frameCount ) {
	if ( !Number.isInteger( frameCount ) || frameCount < 0 ) {
		throw new Error(
			`deferUntilFrame: frameCount must be a non-negative integer, got ${ String( frameCount ) }`
		);
	}

	let remainingFrames = frameCount;
	let cancelled = false;
	/** @type {number|null} */
	let frameHandle = null;

	function tick() {
		// Cleared first: from here on there is no frame left to cancel, and holding a stale
		// handle would have `cancel()` pass an expired id to `cancelAnimationFrame()`.
		frameHandle = null;
		if ( cancelled ) {
			return;
		}
		if ( remainingFrames <= 0 ) {
			callback();
			return;
		}
		remainingFrames--;
		frameHandle = requestAnimationFrame( tick );
	}

	tick();

	return function cancel() {
		cancelled = true;
		if ( frameHandle !== null ) {
			cancelAnimationFrame( frameHandle );
			frameHandle = null;
		}
	};
}

module.exports = deferUntilFrame;
