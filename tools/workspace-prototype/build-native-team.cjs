require('./build-workspace.cjs').main(["team"]).catch(error => { console.error(error.message); process.exitCode = 1; });
