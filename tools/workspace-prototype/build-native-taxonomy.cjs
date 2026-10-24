require('./build-workspace.cjs').main(["taxonomy"]).catch(error => { console.error(error.message); process.exitCode = 1; });
