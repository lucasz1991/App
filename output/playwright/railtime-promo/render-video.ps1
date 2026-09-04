$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$edit = Join-Path $root 'edit'
$final = Join-Path $root 'final'
$logo = 'C:\xampp\htdocs\RailTime\App\public\rt-brand\img\logo-horizontal.png'

New-Item -ItemType Directory -Force -Path $edit, $final | Out-Null

function Invoke-FFmpeg {
    param([string[]] $Arguments)

    & ffmpeg -y -hide_banner -loglevel error @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "FFmpeg ist mit Exitcode $LASTEXITCODE abgebrochen."
    }
}

function Convert-Scene {
    param(
        [string] $InputFile,
        [double] $Start,
        [double] $Duration,
        [string] $OutputFile
    )

    $startText = $Start.ToString('0.###', [Globalization.CultureInfo]::InvariantCulture)
    $durationText = $Duration.ToString('0.###', [Globalization.CultureInfo]::InvariantCulture)
    $fadeOut = ($Duration - 0.25).ToString('0.###', [Globalization.CultureInfo]::InvariantCulture)
    $filter = "fps=25,scale=1440:900:flags=lanczos,format=yuv420p,fade=t=in:st=0:d=0.25,fade=t=out:st=${fadeOut}:d=0.25"

    Invoke-FFmpeg @(
        '-ss', $startText,
        '-i', (Join-Path $root $InputFile),
        '-t', $durationText,
        '-vf', $filter,
        '-an',
        '-c:v', 'libx264',
        '-preset', 'medium',
        '-crf', '18',
        '-pix_fmt', 'yuv420p',
        (Join-Path $edit $OutputFile)
    )
}

Push-Location $root
try {
    Invoke-FFmpeg @(
        '-f', 'lavfi', '-i', 'color=c=0xF7F9FC:s=1440x900:r=25:d=4',
        '-loop', '1', '-framerate', '25', '-i', $logo,
        '-filter_complex', '[1:v]scale=520:-1,format=rgba,fade=t=in:st=0.25:d=0.65:alpha=1,fade=t=out:st=3.35:d=0.45:alpha=1[logo];[0:v][logo]overlay=(W-w)/2:(H-h)/2-90:shortest=1,format=yuv420p[v]',
        '-map', '[v]', '-t', '4', '-an',
        '-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-pix_fmt', 'yuv420p',
        (Join-Path $edit '00-intro.mp4')
    )

    Convert-Scene 'scene-01-dashboard.webm' 10 7 '01-dashboard.mp4'
    Convert-Scene 'scene-02-chat.webm' 8 6.5 '02-chat.mp4'
    Convert-Scene 'scene-03-calls.webm' 5 6.5 '03-calls.mp4'
    Convert-Scene 'scene-04-files.webm' 8 5.5 '04-files.mp4'

    Invoke-FFmpeg @(
        '-loop', '1', '-framerate', '25', '-i', (Join-Path $root 'messages.png'),
        '-t', '5.5',
        '-vf', "zoompan=z='min(zoom+0.00035,1.02)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s=1440x900:fps=25,format=yuv420p,fade=t=in:st=0:d=0.25,fade=t=out:st=5.25:d=0.25",
        '-an', '-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-pix_fmt', 'yuv420p',
        (Join-Path $edit '05-messages.mp4')
    )

    Convert-Scene 'scene-06-mail.webm' 8 7 '06-mail.mp4'
    Convert-Scene 'scene-07-devices.webm' 8 10 '07-devices.mp4'
    Convert-Scene 'scene-08-integrations.webm' 10 8 '08-integrations.mp4'

    Invoke-FFmpeg @(
        '-f', 'lavfi', '-i', 'color=c=0xF7F9FC:s=1440x900:r=25:d=4.5',
        '-loop', '1', '-framerate', '25', '-i', $logo,
        '-filter_complex', '[1:v]scale=500:-1,format=rgba,fade=t=in:st=0.2:d=0.55:alpha=1,fade=t=out:st=3.75:d=0.55:alpha=1[logo];[0:v][logo]overlay=(W-w)/2:(H-h)/2-90:shortest=1,fade=t=out:st=4:d=0.5,format=yuv420p[v]',
        '-map', '[v]', '-t', '4.5', '-an',
        '-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-pix_fmt', 'yuv420p',
        (Join-Path $edit '09-outro.mp4')
    )

    $concat = @(
        '00-intro.mp4',
        '01-dashboard.mp4',
        '02-chat.mp4',
        '03-calls.mp4',
        '04-files.mp4',
        '05-messages.mp4',
        '06-mail.mp4',
        '07-devices.mp4',
        '08-integrations.mp4',
        '09-outro.mp4'
    ) | ForEach-Object { "file '$($_)'" }
    Set-Content -LiteralPath (Join-Path $edit 'concat.txt') -Value $concat -Encoding ascii

    Invoke-FFmpeg @(
        '-f', 'concat', '-safe', '0', '-i', (Join-Path $edit 'concat.txt'),
        '-c', 'copy',
        (Join-Path $edit 'railtime-silent.mp4')
    )

    $captionPath = (Join-Path $root 'captions.ass').Replace('\', '/')
    $captionPath = $captionPath -replace '^([A-Za-z]):', '$1\:'
    Invoke-FFmpeg @(
        '-i', (Join-Path $edit 'railtime-silent.mp4'),
        '-i', (Join-Path $root 'voiceover.wav'),
        '-filter_complex', "[0:v]drawbox=x=0:y=720:w=iw:h=180:color=0xF7F9FC@0.98:t=fill:enable='between(t,4,60)',drawbox=x=0:y=720:w=iw:h=2:color=0xD90429@0.35:t=fill:enable='between(t,4,60)',ass='$captionPath'[v];[1:a]atempo=1.135,highpass=f=75,lowpass=f=14500,loudnorm=I=-16:TP=-1.5:LRA=11,afade=t=in:st=0:d=0.35,afade=t=out:st=63.7:d=0.8,apad=pad_dur=2[a]",
        '-map', '[v]', '-map', '[a]', '-t', '64.5',
        '-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-pix_fmt', 'yuv420p',
        '-c:a', 'aac', '-b:a', '192k', '-ar', '48000',
        '-movflags', '+faststart',
        (Join-Path $final 'RailTime-App-Kurzvorstellung.mp4')
    )

    Invoke-FFmpeg @(
        '-ss', '2', '-i', (Join-Path $final 'RailTime-App-Kurzvorstellung.mp4'),
        '-frames:v', '1', '-q:v', '2',
        (Join-Path $final 'RailTime-App-Kurzvorstellung-Vorschau.jpg')
    )
}
finally {
    Pop-Location
}
