require('./build-workspace.cjs').main(["navigation"]).catch(error => { console.error(error.message); process.exitCode = 1; });
