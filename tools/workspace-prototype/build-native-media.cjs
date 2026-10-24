require('./build-workspace.cjs').main(["media"]).catch(error => { console.error(error.message); process.exitCode = 1; });
