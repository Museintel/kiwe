require('./build-workspace.cjs').main(["seo-object"]).catch(error => { console.error(error.message); process.exitCode = 1; });
