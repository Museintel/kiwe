require('./build-workspace.cjs').main(["backups"]).catch(error => { console.error(error.message); process.exitCode = 1; });
