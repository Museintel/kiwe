require('./build-workspace.cjs').main(["identity"]).catch(error => { console.error(error.message); process.exitCode = 1; });
