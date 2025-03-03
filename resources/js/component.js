import Pagination from "@/components/common/pagination"
import Dialog from "@/components/common/dialog"
import Message from "@/components/common/message"
import Loading from "@/components/common/loading"
import Progress from "@/components/common/progress"
import Upload from "@/components/common/upload"
import Image from "@/components/common/image"
import Table from "@/components/common/table"

const components = [
  Pagination,
  Progress,
  Upload,
  Image,
  Table
]

const install = function(Vue, opts = {}) {
  components.forEach(component => {
    Vue.component(component.name, component)
  })

  Vue.use(Loading.directive)

  Vue.prototype.$Loading = Loading.loading
  Vue.prototype.$Dialog = Dialog
  Vue.prototype.$Message = Message
}

if (typeof window !== 'undefined' && window.Vue) {
  install(window.Vue)
}

export default {
  install,
  ...components
}
