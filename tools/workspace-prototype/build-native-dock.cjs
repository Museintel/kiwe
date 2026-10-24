require('./build-workspace.cjs').main(["dock"]).catch(error => { console.error(error.message); process.exitCode = 1; });
