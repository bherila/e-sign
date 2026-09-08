import { useEffect, useState } from "react";

/**
 * The current device pixel ratio, updated when it changes.
 *
 * It changes more often than it looks: dragging a window between a Retina display and an
 * external monitor, or zooming the browser, both move it. A canvas rasterised at the old ratio
 * stays on screen looking soft until something else re-renders it, so the value is watched
 * rather than read once.
 *
 * `matchMedia` on the exact current resolution is the standard way to be told: the query stops
 * matching the moment the ratio moves, and a fresh query is registered for the new value.
 * Falls back to 1 where `window` is absent (tests, server rendering).
 */
export function useDevicePixelRatio(): number {
  const [ratio, setRatio] = useState(() =>
    typeof window === "undefined" ? 1 : window.devicePixelRatio || 1,
  );

  useEffect(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
      return;
    }

    const query = window.matchMedia(`(resolution: ${window.devicePixelRatio}dppx)`);
    const onChange = (): void => setRatio(window.devicePixelRatio || 1);

    query.addEventListener("change", onChange);

    return () => query.removeEventListener("change", onChange);
  }, [ratio]);

  return ratio;
}
