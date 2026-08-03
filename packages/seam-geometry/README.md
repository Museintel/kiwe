# SEAM Page Geometry Solver

The solver compares rendered observations across the capture viewport matrix. It infers width behavior, layout and position models, visibility changes, and the smallest responsive property delta for each node.

It is separate from Kiwe's runtime AppShell Geometry Engine: this package analyzes page content on the compiler plane; the runtime engine continues to own Dock/Sheet/Screen placement in WordPress.
