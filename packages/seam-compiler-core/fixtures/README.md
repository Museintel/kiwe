# Compiler fixtures

`landing-hero` and `editorial-card` are deliberately unrelated synthetic captures. They prevent the compiler skeleton from learning one supplied homepage's structure.

`golden/national-chikki` preserves the user's supplied homepage source and source hash as the first real regression artifact. It is intentionally marked `captureStatus: pending-m2`: M1 does not fabricate rendered measurements. The M2 browser capture engine will generate its multi-viewport `seam.capture.v1` evidence.
