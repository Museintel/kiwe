require('./build-workspace.cjs').main(["continuity"]).catch(error => { console.error(error.message); process.exitCode = 1; });
