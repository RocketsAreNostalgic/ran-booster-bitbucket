#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
fixtures="$repo_root/tests/Workflow/fixtures"
work_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-bitbucket-release-state-test.XXXXXX")
trap 'rm -rf "$work_root"' EXIT

mock_bin="$work_root/bin"
mkdir -p "$mock_bin"
cat > "$mock_bin/gh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == api && $# -eq 2 ]]
endpoint=$2
case "${TAG_SCENARIO}:${endpoint}" in
	lightweight:*/git/ref/tags/v1.2.3)
		file=tag-lightweight-ref.json
		;;
	annotated:*/git/ref/tags/v1.2.3|annotated-shadow:*/git/ref/tags/v1.2.3)
		file=tag-annotated-ref.json
		;;
	annotated:*/git/tags/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb)
		file=tag-annotated-object.json
		;;
	annotated-shadow:*/git/tags/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb)
		file=tag-annotated-shadow-object.json
		;;
	shadow:*/git/ref/tags/v1.2.3)
		file=tag-shadow-ref.json
		;;
	*)
		exit 1
		;;
esac
cat "$FIXTURE_DIR/$file"
EOF
chmod +x "$mock_bin/gh"

tag_verifier="$repo_root/scripts/verify-release-tag-target.sh"
expected_commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
for scenario in lightweight annotated; do
	PATH="$mock_bin:$PATH" FIXTURE_DIR="$fixtures" TAG_SCENARIO="$scenario" \
		"$tag_verifier" RocketsAreNostalgic/ran-booster-bitbucket v1.2.3 "$expected_commit" >/dev/null
done
for scenario in shadow annotated-shadow; do
	if PATH="$mock_bin:$PATH" FIXTURE_DIR="$fixtures" TAG_SCENARIO="$scenario" \
		"$tag_verifier" RocketsAreNostalgic/ran-booster-bitbucket v1.2.3 "$expected_commit" >/dev/null 2>&1; then
		printf 'shadow tag scenario %s was accepted\n' "$scenario" >&2
		exit 1
	fi
done

quality="$work_root/quality"
remote="$work_root/remote"
mkdir -p "$quality" "$remote"
archive=ran-booster-bitbucket-1.2.3.zip
checksum="${archive}.sha256"
printf 'verified archive bytes\n' > "$quality/$archive"
printf 'verified checksum bytes\n' > "$quality/$checksum"
cp "$quality/$archive" "$remote/$archive"
cp "$quality/$checksum" "$remote/$checksum"
asset_verifier="$repo_root/scripts/verify-immutable-release-assets.sh"
"$asset_verifier" "$quality/$archive" "$quality/$checksum" "$remote" \
	< "$fixtures/immutable-assets-exact.json" >/dev/null
printf 'replaced published bytes\n' > "$remote/$archive"
if "$asset_verifier" "$quality/$archive" "$quality/$checksum" "$remote" \
	< "$fixtures/immutable-assets-exact.json" >/dev/null 2>&1; then
	printf 'replaced published archive bytes were accepted\n' >&2
	exit 1
fi
cp "$quality/$archive" "$remote/$archive"
if "$asset_verifier" "$quality/$archive" "$quality/$checksum" "$remote" \
	< "$fixtures/immutable-assets-extra.json" >/dev/null 2>&1; then
	printf 'published release with an extra asset was accepted\n' >&2
	exit 1
fi

run_fixture="$fixtures/retry-runs.json"
run_selector="$repo_root/scripts/has-trusted-release-candidate-run.sh"
branch=release-please--branches--main--components--ran-booster-bitbucket
head_sha=dddddddddddddddddddddddddddddddddddddddd
repository=RocketsAreNostalgic/ran-booster-bitbucket
select_run() {
	jq --argjson id "$1" '{workflow_runs: [.workflow_runs[] | select(.id == $id)]}' "$run_fixture"
}
for id in 1 2; do
	select_run "$id" | "$run_selector" 42 "$branch" "$head_sha" "$repository" \
		|| { printf 'trusted active/successful run %s did not suppress retry\n' "$id" >&2; exit 1; }
done
for id in 3 4 5 6; do
	if select_run "$id" | "$run_selector" 42 "$branch" "$head_sha" "$repository"; then
		printf 'failed, cancelled, or non-bot run %s suppressed retry\n' "$id" >&2
		exit 1
	fi
done

release_workflow="$repo_root/.github/workflows/release-please.yml"
draft_step=$(sed -n \
	'/      - name: Create or reuse draft and attach verified assets/,/      - name: Publish only under immutable-release contract/p' \
	"$release_workflow")
grep -F 'releases?per_page=100' <<< "$draft_step" >/dev/null
grep -F 'releases/assets/${archive_asset_id}' <<< "$draft_step" >/dev/null
grep -F 'releases/assets/${checksum_asset_id}' <<< "$draft_step" >/dev/null
if grep -F 'releases/tags/${RAN_RELEASE_TAG}' <<< "$draft_step" >/dev/null \
	|| grep -F 'gh release download "$RAN_RELEASE_TAG"' <<< "$draft_step" >/dev/null; then
	printf 'draft readback relies on a tag endpoint that excludes draft releases\n' >&2
	exit 1
fi
action_parser="$work_root/release-action-parser.sh"
{
	printf '%s\n' '#!/usr/bin/env bash' 'set -euo pipefail'
	printf '%s\n' "release_branch='release-please--branches--main--components--ran-booster-bitbucket'"
	awk '
		/^          expected_files=/ { capture = 1 }
		capture {
			end = ( $0 == "          fi" )
			sub( /^          /, "" )
			print
			if ( end ) {
				exit
			}
		}
	' "$release_workflow"
	printf '%s\n' 'test "$action_pr_number" = 54'
} > "$action_parser"

release_action_pr() {
	jq -nc --argjson files "$1" '[{
		number: 54,
		baseBranchName: "main",
		headBranchName: "release-please--branches--main--components--ran-booster-bitbucket",
		files: $files
	}]'
}

assert_action_payload_accepted() {
	RAN_RELEASE_PRS_CREATED=true \
		RAN_RELEASE_PRS="$1" \
		bash "$action_parser"
}

assert_action_files_rejected() {
	if assert_action_payload_accepted "$(release_action_pr "$1")" >/dev/null 2>&1; then
		printf 'invalid Release Please action files were accepted: %s\n' "$1" >&2
		exit 1
	fi
}

assert_action_payload_accepted "$(release_action_pr '[]')"
assert_action_payload_accepted "$(release_action_pr '[{"filename":"readme.txt"},"CHANGELOG.md",{"path":"ran-booster-bitbucket.php"},".release-please-manifest.json"]')"
assert_action_files_rejected '["CHANGELOG.md"]'
assert_action_files_rejected '[".release-please-manifest.json","CHANGELOG.md","ran-booster-bitbucket.php","readme.txt","arbitrary.txt"]'
assert_action_files_rejected '[".release-please-manifest.json","CHANGELOG.md","ran-booster-bitbucket.php","readme.txt","readme.txt"]'
assert_action_files_rejected '["arbitrary.txt"]'
assert_action_files_rejected 'null'
missing_files=$(jq -nc '[{
	number: 54,
	baseBranchName: "main",
	headBranchName: "release-please--branches--main--components--ran-booster-bitbucket"
}]')
if assert_action_payload_accepted "$missing_files" >/dev/null 2>&1; then
	printf 'Release Please action output without files was accepted\n' >&2
	exit 1
fi

quality_workflow="$repo_root/.github/workflows/quality.yml"
release_doc="$repo_root/RELEASE.md"
quality_paths_file="$work_root/quality-trust-paths.txt"
documented_paths_file="$work_root/documented-trust-paths.txt"

awk '
	/for trust_path in \\/ { capture = 1; next }
	capture {
		line = $0
		sub(/^[[:space:]]+/, "", line)
		if (line ~ /; do$/) {
			sub(/[[:space:]]*; do$/, "", line)
			if (length(line)) print line
			exit
		}
		sub(/[[:space:]]*\\$/, "", line)
		if (length(line)) print line
	}
' "$quality_workflow" | sort -u > "$quality_paths_file"

awk '
	/^The ordinary evidence inputs currently covered by Quality.s fresh-evidence$/ { capture = 1; next }
	capture && /^`Quality` is the executable authority/ { exit }
	capture {
		line = $0
		while (match(line, /`[^`]+`/)) {
			print substr(line, RSTART + 1, RLENGTH - 2)
			line = substr(line, RSTART + RLENGTH)
		}
	}
' "$release_doc" | sort -u > "$documented_paths_file"

[[ -s "$quality_paths_file" ]] \
	|| { printf 'Quality freshness classifier path set is empty\n' >&2; exit 1; }
[[ -s "$documented_paths_file" ]] \
	|| { printf 'RELEASE.md freshness inventory path set is empty\n' >&2; exit 1; }
if ! diff -u "$quality_paths_file" "$documented_paths_file"; then
	printf 'Quality freshness classifier and RELEASE.md inventory differ\n' >&2
	exit 1
fi

if grep -F 'for trust_path in \' "$release_workflow" >/dev/null; then
	printf 'Release Please still owns a duplicated release-control path catalogue\n' >&2
	exit 1
fi
if grep -F 'privileged reconciliation is intentionally deferred' "$release_workflow" >/dev/null; then
	printf 'Release Please still contains the mandatory second-merge self-deferral\n' >&2
	exit 1
fi

release_on=$(sed -n '/^on:/,/^permissions:/p' "$release_workflow")
if grep -F 'pull_request:' <<< "$release_on" >/dev/null \
	|| grep -F 'pull_request_target:' <<< "$release_on" >/dev/null; then
	printf 'Release Please can be entered directly from pull-request code\n' >&2
	exit 1
fi

release_job_gate=$(awk '
	/^    if: >-$/ { capture = 1 }
	capture {
		print
		if ( $0 ~ /^    runs-on:/ ) {
			exit
		}
	}
' "$release_workflow")
for predicate in \
	"github.event.workflow_run.event == 'push'" \
	"github.event.workflow_run.conclusion == 'success'" \
	"github.event.workflow_run.head_branch == 'main'" \
	'github.event.workflow_run.head_repository.full_name == github.repository' \
	"github.event.workflow_run.path == '.github/workflows/quality.yml'"; do
	grep -F "$predicate" <<< "$release_job_gate" >/dev/null \
		|| { printf 'Release Please admission gate is missing: %s\n' "$predicate" >&2; exit 1; }
done

grep -F 'test "$(git rev-parse HEAD)" = "$RAN_QUALITY_COMMIT"' "$release_workflow" >/dev/null
grep -F 'and .merge_commit_sha == $merge' "$release_workflow" >/dev/null
grep -F 'merged_pr_number=' "$release_workflow" >/dev/null
grep -F "jq -er '.number' <<< \"\$merged_pr\"" "$release_workflow" >/dev/null

stale_guard="$work_root/stale-main-guard.sh"
{
	printf '%s\n' '#!/usr/bin/env bash' 'set -euo pipefail'
	awk '
		/^          current_main=/ { capture = 1 }
		capture {
			if ($0 ~ /^          pr_pages=/) exit
			sub(/^          /, "")
			print
		}
	' "$release_workflow"
	printf '%s\n' 'printf "reached-after-stale-guard\n" >> "$STALE_SENTINEL"'
} > "$stale_guard"
chmod +x "$stale_guard"

stale_bin="$work_root/stale-bin"
mkdir -p "$stale_bin"
cat > "$stale_bin/gh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == api ]]
printf '%s\n' "$MOCK_CURRENT_MAIN"
EOF
chmod +x "$stale_bin/gh"

quality_commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
newer_main=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
stale_output="$work_root/stale-output"
stale_sentinel="$work_root/stale-sentinel"
: > "$stale_output"
PATH="$stale_bin:$PATH" \
	GITHUB_REPOSITORY=RocketsAreNostalgic/ran-booster-bitbucket \
	GITHUB_OUTPUT="$stale_output" \
	MOCK_CURRENT_MAIN="$newer_main" \
	RAN_QUALITY_COMMIT="$quality_commit" \
	STALE_SENTINEL="$stale_sentinel" \
	bash "$stale_guard"
grep -Fx 'release-required=false' "$stale_output" >/dev/null
grep -Fx 'release-please-required=false' "$stale_output" >/dev/null
[[ ! -e "$stale_sentinel" ]] \
	|| { printf 'Stale Quality evidence fell through the current-main guard\n' >&2; exit 1; }

current_output="$work_root/current-output"
current_sentinel="$work_root/current-sentinel"
: > "$current_output"
PATH="$stale_bin:$PATH" \
	GITHUB_REPOSITORY=RocketsAreNostalgic/ran-booster-bitbucket \
	GITHUB_OUTPUT="$current_output" \
	MOCK_CURRENT_MAIN="$quality_commit" \
	RAN_QUALITY_COMMIT="$quality_commit" \
	STALE_SENTINEL="$current_sentinel" \
	bash "$stale_guard"
[[ -e "$current_sentinel" ]] \
	|| { printf 'Current exact-main Quality evidence was stopped by the stale guard\n' >&2; exit 1; }
[[ ! -s "$current_output" ]] \
	|| { printf 'Current exact-main guard unexpectedly wrote release outputs\n' >&2; exit 1; }

assert_step_gate() {
	local step_name=$1 expected_gate=$2 step
	step=$(awk -v marker="      - name: $step_name" '
		$0 == marker { capture = 1; seen = 0 }
		capture {
			if ( seen && $0 ~ /^      - name:/ ) {
				exit
			}
			print
			seen = 1
		}
	' "$release_workflow")
	[[ -n "$step" ]] || { printf 'Release step is missing: %s\n' "$step_name" >&2; exit 1; }
	grep -F "$expected_gate" <<< "$step" >/dev/null \
		|| { printf 'Release step is not bound to admission: %s\n' "$step_name" >&2; exit 1; }
}

assert_step_gate 'Open or update release pull request' "if: steps.release-state.outputs.release-please-required == 'true'"
assert_step_gate 'Validate and dispatch exact Release Please candidate' "if: steps.release-state.outputs.release-please-required == 'true'"
assert_step_gate 'Download exact archive admitted by main Quality' "if: steps.release-state.outputs.release-required == 'true'"
assert_step_gate 'Verify release identity and exact artifact provenance' "if: steps.release-state.outputs.release-required == 'true'"
assert_step_gate 'Create or reuse draft and attach verified assets' "if: steps.release-state.outputs.release-required == 'true' && env.RAN_RELEASE_PENDING == 'true'"
assert_step_gate 'Publish only under immutable-release contract' "if: steps.release-state.outputs.release-required == 'true' && env.RAN_RELEASE_PENDING == 'true'"
assert_step_gate 'Read back immutable release and reconcile exact PR' "if: steps.release-state.outputs.release-required == 'true'"

workflow_run_qualifies() {
	[[ "$1" == push \
		&& "$2" == success \
		&& "$3" == main \
		&& "$4" == RocketsAreNostalgic/ran-booster-bitbucket \
		&& "$5" == .github/workflows/quality.yml ]]
}
for invalid in \
	'pull_request success main RocketsAreNostalgic/ran-booster-bitbucket .github/workflows/quality.yml' \
	'push failure main RocketsAreNostalgic/ran-booster-bitbucket .github/workflows/quality.yml' \
	'push success feature RocketsAreNostalgic/ran-booster-bitbucket .github/workflows/quality.yml' \
	'push success main someone/else .github/workflows/quality.yml' \
	'push success main RocketsAreNostalgic/ran-booster-bitbucket .github/workflows/other-quality.yml'; do
	read -r event conclusion branch_name head_repository workflow_path <<< "$invalid"
	if workflow_run_qualifies "$event" "$conclusion" "$branch_name" "$head_repository" "$workflow_path"; then
		printf 'Unqualified workflow_run was accepted: %s\n' "$invalid" >&2
		exit 1
	fi
done
workflow_run_qualifies push success main RocketsAreNostalgic/ran-booster-bitbucket .github/workflows/quality.yml \
	|| { printf 'Qualified canonical exact-main workflow_run was rejected\n' >&2; exit 1; }

printf 'Release tag, immutable asset, retry actor, and trusted-main promotion fixtures passed.\n'