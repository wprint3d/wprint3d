variable "REGISTRY" {
  default = "docker.io/wprint3d"
}

variable "TAG" {
  default = "latest"
}

variable "TAG_SUFFIX" {
  default = ""
}

variable "PLATFORM" {
  default = "linux/amd64"
}

variable "VCS_REF" {
  default = "unknown"
}

group "default" {
  targets = ["backend", "mapper", "streamer", "proxy", "frontend"]
}

group "production" {
  targets = ["backend", "mapper", "streamer", "proxy", "frontend"]
}

target "backend-common" {
  context    = "."
  dockerfile = "Dockerfile"
  platforms  = [PLATFORM]
  args = {
    VCS_REF = VCS_REF
  }
}

target "backend" {
  inherits = ["backend-common"]
  target   = "backend"
  tags     = ["${REGISTRY}/wprint3d:${TAG}${TAG_SUFFIX}"]
}

target "mapper" {
  inherits = ["backend-common"]
  target   = "mapper"
  tags     = ["${REGISTRY}/wprint3d-mapper:${TAG}${TAG_SUFFIX}"]
}

target "streamer" {
  inherits = ["backend-common"]
  target   = "streamer"
  tags     = ["${REGISTRY}/wprint3d-streamer:${TAG}${TAG_SUFFIX}"]
}

target "proxy" {
  context    = "."
  dockerfile = "Dockerfile.proxy"
  platforms  = [PLATFORM]
  tags       = ["${REGISTRY}/wprint3d-proxy:${TAG}${TAG_SUFFIX}"]
}

target "frontend" {
  context    = "frontend"
  dockerfile = "Dockerfile"
  target     = "production"
  platforms  = [PLATFORM]
  tags       = ["${REGISTRY}/wprint3d-frontend:${TAG}${TAG_SUFFIX}"]
}
