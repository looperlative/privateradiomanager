# Enable telnet server for remote control
settings.server.telnet.set(true)
settings.server.telnet.bind_addr.set("127.0.0.1")
settings.server.telnet.port.set(1234)

def xfade(s) =
  crossfade(duration=2.0, fade_in=2.0, fade_out=2.0, s)
end

###############################################################
# Playlists
###############################################################
heavy  = playlist(mode="randomize", reload_mode="rounds", reload=1, "YOUR_TOP_LEVEL_DIRECTORY/radio/heavy")
medium = playlist(mode="randomize", reload_mode="rounds", reload=1, "YOUR_TOP_LEVEL_DIRECTORY/radio/medium")
light  = playlist(mode="randomize", reload_mode="rounds", reload=1, "YOUR_TOP_LEVEL_DIRECTORY/radio/light")
announcers = playlist(mode="randomize", reload_mode="rounds", reload=1, "YOUR_TOP_LEVEL_DIRECTORY/radio/announcers")
music = random(weights=[5, 4, 3], [heavy, medium, light])

# Play announcer every 20 minutes (3 times per hour)
# delay makes announcers unavailable for 1200s after each play
announcers_timed = delay(initial=false, 1200., announcers)
normal_rotation = fallback(track_sensitive=true, [announcers_timed, music])

###############################################################
# Request Queue (for prerecorded programs)
###############################################################
# Queue for on-demand programs - interrupts normal rotation
programs = request.queue(id="programs")

# Programs take priority over normal rotation
radio_raw = fallback(track_sensitive=true, [programs, normal_rotation])

###############################################################
# Logging
###############################################################
log_file = "YOUR_LOG_DIRECTORY/playback.log"

def log_track(m) =
  title = m["title"]
  artist = m["artist"]
  filename = m["filename"]
  timestamp = time.string("%Y-%m-%d %H:%M:%S")
  log.important("Playing: #{artist} - #{title} (#{filename})")
  file.write(append=true, data="#{timestamp} | Playing: #{artist} - #{title} | #{filename}\n", log_file)
end

radio_logged = source.on_track(radio_raw, log_track)
radio_xf = xfade(radio_logged)

# Track when mksafe activates (source becomes unavailable)
last_was_blank = ref(false)

def check_blank() =
  if not source.is_ready(radio_logged) and not !last_was_blank then
    timestamp = time.string("%Y-%m-%d %H:%M:%S")
    log.important("mksafe activated - playing blank audio")
    file.write(append=true, data="#{timestamp} | mksafe activated - playing blank audio\n", log_file)
    last_was_blank := true
  elsif source.is_ready(radio_logged) then
    last_was_blank := false
  end
  1.0
end

thread.run.recurrent(delay=0.0, check_blank)

radio = mksafe(radio_xf)

###############################################################
# Audio Processing
###############################################################
# Normalize volume levels between tracks
radio = normalize(radio)

###############################################################
# Output to Icecast
###############################################################
output.icecast(
  %ogg(%opus(bitrate=96)),
  host = "127.0.0.1",
  port = 8000,
  password = "YOUR_ICECAST_LISTENER_PASSWORD",
  mount = "stream.ogg",
  name = "You Radio Name",
  description = "Your Radio Description",
  genre = "Variety",
  radio
)

