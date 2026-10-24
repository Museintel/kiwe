require('./build-workspace.cjs').main(["posts"]).catch(error => { console.error(error.message); process.exitCode = 1; });
