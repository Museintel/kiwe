require('./build-workspace.cjs').main(["guest","notification","commerce","ai","templates","analytics","trash"]).catch(error => { console.error(error.message); process.exitCode = 1; });
